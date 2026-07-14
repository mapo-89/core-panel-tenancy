<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Tenancy;

use CorePanel\Support\Permissions\PermissionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

final readonly class CentralImpersonationContext
{
    public const QUERY_KEY = 'context';

    public const SESSION_KEY = 'tenant_impersonation.central_user_id';

    public function __construct(private PermissionService $permissions) {}

    public function encryptedPayload(Authenticatable $user): string
    {
        return Crypt::encryptString(json_encode([
            'central_user_id' => (string) $user->getAuthIdentifier(),
        ], JSON_THROW_ON_ERROR));
    }

    public function storeFromEncryptedPayload(Request $request, ?string $payload): void
    {
        if (! $request->hasSession() || ! is_string($payload) || trim($payload) === '') {
            return;
        }

        try {
            /** @var mixed $data */
            $data = json_decode(Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return;
        }

        $centralUserId = is_array($data) ? ($data['central_user_id'] ?? null) : null;

        if (! is_string($centralUserId) || trim($centralUserId) === '') {
            return;
        }

        $request->session()->put(self::SESSION_KEY, trim($centralUserId));
    }

    public function centralUser(Request $request): ?Authenticatable
    {
        return $this->runInCentralContext(fn (): ?Authenticatable => $this->resolveCentralUser($request));
    }

    public function authorizedCentralUser(Request $request, string $permission = 'tenants.update'): ?Authenticatable
    {
        return $this->runInCentralContext(function () use ($request, $permission): ?Authenticatable {
            $user = $this->resolveCentralUser($request);

            if (! $user instanceof Authenticatable) {
                return null;
            }

            return $this->permissions->userHas($user, $permission)
                ? $user
                : null;
        });
    }

    public function forget(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget(self::SESSION_KEY);
    }

    public function restoreCentralAuthentication(Request $request): ?Authenticatable
    {
        $user = $this->authorizedCentralUser($request);

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $guard = Auth::guard(config('auth.defaults.guard'));
        $guard->logout();
        $guard->login($user);
        $this->forget($request);

        return $user;
    }

    public function centralAppUrl(Request $request): string
    {
        $host = (string) (config('tenancy.central_domains')[0] ?? parse_url((string) config('app.url'), PHP_URL_HOST) ?? 'localhost');
        $port = $request->getPort();

        if (! in_array($port, [80, 443], true) && ! str_contains($host, ':')) {
            $host .= ':'.$port;
        }

        return sprintf(
            '%s://%s%s',
            $request->getScheme(),
            $host,
            route('core-panel.dashboard', absolute: false),
        );
    }

    private function resolveCentralUser(Request $request): ?Authenticatable
    {
        if (! $request->hasSession()) {
            return null;
        }

        $centralUserId = $request->session()->get(self::SESSION_KEY);

        if (! is_string($centralUserId) || trim($centralUserId) === '') {
            return null;
        }

        $modelClass = (string) config(
            'core-panel.user_model',
            (string) config('auth.providers.users.model'),
        );

        if ($modelClass === '' || ! class_exists($modelClass)) {
            return null;
        }

        $model = new $modelClass;

        if (! method_exists($model, 'setConnection') || ! method_exists($model, 'newQuery')) {
            return null;
        }

        $centralConnection = (string) config(
            'tenancy.database.central_connection',
            (string) config('database.default', 'pgsql'),
        );

        if ($centralConnection !== '') {
            $model->setConnection($centralConnection);
        }

        $user = $model->newQuery()->find(trim($centralUserId));

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runInCentralContext(callable $callback): mixed
    {
        if (! function_exists('tenancy')) {
            return $callback();
        }

        $currentTenant = tenancy()->initialized
            ? tenancy()->tenant
            : null;

        if ($currentTenant !== null) {
            tenancy()->end();
        }

        try {
            return $callback();
        } finally {
            if ($currentTenant instanceof TenantContract) {
                tenancy()->initialize($currentTenant);
            }
        }
    }
}
