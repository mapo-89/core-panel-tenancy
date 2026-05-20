<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Controllers\TenantAssetsController;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

class TenancyServiceProvider extends ServiceProvider
{
    public static string $controllerNamespace = '';

    /**
     * @return array<class-string, array<int, mixed>>
     */
    public function events(): array
    {
        return [
            Events\CreatingTenant::class => [],
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,
                    Jobs\MigrateDatabase::class,
                    Jobs\SeedDatabase::class,
                ])->send(static function (Events\TenantCreated $event) {
                    return $event->tenant;
                })->shouldBeQueued(false),
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                ])->send(static function (Events\TenantDeleted $event) {
                    return $event->tenant;
                })->shouldBeQueued(false),
            ],
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],
            Events\DatabaseCreated::class => [],
            Events\DatabaseMigrated::class => [],
            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],
            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],
            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function boot(): void
    {
        $this->registerTenancyAwareAuthentication();
        TenantAssetsController::$tenancyMiddleware = Middleware\InitializeTenancyByDomain::class;

        $this->bootEvents();
        $this->mapRoutes();
        $this->makeTenancyMiddlewareHighestPriority();
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes(): void
    {
        $this->app->booted(function (): void {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }

            if (file_exists(base_path('routes/tenant-api.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant-api.php'));
            }
        });
    }

    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            Middleware\PreventAccessFromCentralDomains::class,
            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }

    protected function registerTenancyAwareAuthentication(): void
    {
        if (! function_exists('tenancy') || ! class_exists(Fortify::class) || ! config('auth.providers.users.model')) {
            return;
        }

        Fortify::authenticateUsing(function (Request $request): ?Authenticatable {
            $modelClass = (string) config('auth.providers.users.model', User::class);

            if (! class_exists($modelClass)) {
                return null;
            }

            $model = new $modelClass;

            if (! $model instanceof Model) {
                return null;
            }

            if (! tenancy()->initialized) {
                $centralConnection = (string) config(
                    'tenancy.database.central_connection',
                    (string) config('database.default', 'pgsql'),
                );

                if ($centralConnection !== '') {
                    $model->setConnection($centralConnection);
                }
            }

            $query = $model->newQuery()
                ->where('email', $request->string(Fortify::username())->toString());

            if (method_exists($model, 'microsoftAccount')) {
                $query = $query->with('microsoftAccount');
            }

            $user = $query->first();

            if (! $user instanceof Model || ! $user instanceof Authenticatable) {
                return null;
            }

            if (
                method_exists($user, 'supportsCorePanelStatus')
                && $user->supportsCorePanelStatus()
                && method_exists($user, 'corePanelUserStatus')
                && $user->corePanelUserStatus() !== 'active'
            ) {
                return null;
            }

            if (
                method_exists($user, 'requiresPasswordSetup')
                && $user->requiresPasswordSetup()
                && method_exists($user, 'microsoftAccount')
                && $user->microsoftAccount !== null
            ) {
                throw ValidationException::withMessages([
                    Fortify::username() => [__('page-auth.socialite.microsoft_password_required')],
                ]);
            }

            if (! Hash::check($request->string('password')->toString(), $user->getAuthPassword())) {
                return null;
            }

            return $user;
        });
    }
}
