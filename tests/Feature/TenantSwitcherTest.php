<?php

declare(strict_types=1);

use CorePanelTenancy\Http\Middleware\RedirectImpersonatingTenantGuest;
use CorePanelTenancy\Support\Tenancy\CentralImpersonationContext;
use CorePanelTenancy\Support\Tenancy\TenantSwitcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Models\Tenant;

final class ConfiguredCorePanelUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';
}

it('uses the eager loaded domains relation when resolving the primary domain', function (): void {
    $switcher = app(TenantSwitcher::class);
    $tenant = new class extends Model
    {
        protected $guarded = [];

        public function domains(): never
        {
            throw new RuntimeException('domains() should not be queried when the relation is already loaded.');
        }
    };

    $firstDomain = new class extends Model
    {
        protected $guarded = [];
    };
    $firstDomain->forceFill([
        'id' => 10,
        'domain' => 'beta.example.test',
    ]);

    $secondDomain = new class extends Model
    {
        protected $guarded = [];
    };
    $secondDomain->forceFill([
        'id' => 5,
        'domain' => 'alpha.example.test',
    ]);

    $tenant->setRelation('domains', new EloquentCollection([
        $firstDomain,
        $secondDomain,
    ]));

    $method = new ReflectionMethod($switcher, 'primaryDomain');
    $method->setAccessible(true);

    expect($method->invoke($switcher, $tenant))
        ->toBe('alpha.example.test');
});

it('runs central impersonation callbacks outside the tenant context and restores the tenant afterwards', function (): void {
    $tenant = new Tenant;
    $tenant->forceFill([
        'id' => 'tenant-alpha',
    ]);

    tenancy()->getBootstrappersUsing = static fn (): array => [];
    tenancy()->initialize($tenant);

    $method = new ReflectionMethod(CentralImpersonationContext::class, 'runInCentralContext');
    $method->setAccessible(true);
    $initializedInsideCallback = $method->invoke(
        app(CentralImpersonationContext::class),
        static fn (): bool => tenancy()->initialized,
    );

    expect($initializedInsideCallback)->toBeFalse();
});

it('resolves the configured core panel user model before the auth provider model', function (): void {
    $this->migrateScaffoldDatabase();

    config()->set('core-panel.user_model', ConfiguredCorePanelUser::class);
    config()->set('auth.providers.users.model', stdClass::class);
    config()->set('tenancy.database.central_connection', 'sqlite');

    $user = ConfiguredCorePanelUser::query()->create([
        'id' => 'central-user-id',
        'email' => 'central@example.test',
        'first_name' => 'Central',
        'last_name' => 'Admin',
        'password' => bcrypt('password'),
    ]);

    $request = Request::create('/tenant/dashboard');
    $session = app('session.store');
    $session->start();
    $session->put(CentralImpersonationContext::SESSION_KEY, $user->getAuthIdentifier());
    $request->setLaravelSession($session);

    expect(app(CentralImpersonationContext::class)->centralUser($request))
        ->toBeInstanceOf(ConfiguredCorePanelUser::class)
        ->getAuthIdentifier()->toBe($user->getAuthIdentifier());
});

it('redirects impersonating tenant guests to leave impersonation instead of the tenant login', function (): void {
    $this->migrateScaffoldDatabase();

    config()->set('core-panel.user_model', ConfiguredCorePanelUser::class);
    config()->set('auth.providers.users.model', stdClass::class);
    config()->set('tenancy.database.central_connection', 'sqlite');

    $user = ConfiguredCorePanelUser::query()->create([
        'id' => 'central-user-id',
        'email' => 'central@example.test',
        'first_name' => 'Central',
        'last_name' => 'Admin',
        'password' => bcrypt('password'),
    ]);

    Route::middleware(['web', RedirectImpersonatingTenantGuest::class])
        ->name('tenant.')
        ->group(function (): void {
            Route::get('/tenant/dashboard', static fn (): string => 'tenant-dashboard')->name('dashboard');
            Route::get('/tenant/leave-impersonation', static fn (): string => 'leave')->name('leave-impersonation');
        });

    $response = $this->withSession([
        CentralImpersonationContext::SESSION_KEY => $user->getAuthIdentifier(),
    ])->get('/tenant/dashboard');

    $response->assertRedirect(route('tenant.leave-impersonation'));
});
