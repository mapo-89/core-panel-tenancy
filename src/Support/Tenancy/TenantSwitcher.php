<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Tenancy;

use CorePanel\Support\Permissions\PermissionService;
use CorePanel\Support\Settings\SettingsLogoManager;
use CorePanel\Support\Settings\SettingsRepository;
use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

final readonly class TenantSwitcher
{
    private const CENTRAL_VALUE = '__central__';

    public function __construct(
        private CentralImpersonationContext $impersonationContext,
        private PermissionService $permissions,
        private SettingsRepository $settings,
        private SettingsLogoManager $settingsLogo,
        private TenantModelResolver $tenants,
    ) {}

    /**
     * @return array{
     *     current_value:string,
     *     options:list<array{
     *         action:'central'|'tenant',
     *         label:string,
     *         logo_url:?string,
     *         meta:?string,
     *         method:'get'|'post',
     *         url:string,
     *         value:string
     *     }>
     * }|null
     */
    public function forRequest(Request $request): ?array
    {
        $actor = $this->authorizedActor($request);

        if (! $actor instanceof Authenticatable) {
            return null;
        }

        $impersonateRoute = $this->impersonateRouteName($request);

        if ($impersonateRoute === null) {
            return null;
        }

        $tenantModel = $this->tenants->tenantModelClass();
        $tenantOptions = $tenantModel::query()
            ->with('domains')
            ->orderBy('id')
            ->get()
            ->map(fn (Model $tenant): array => $this->tenantOption($tenant, $impersonateRoute))
            ->values()
            ->all();

        if ($tenantOptions === []) {
            return null;
        }

        return [
            'current_value' => $request->routeIs('tenant.*') && tenancy()->initialized
                ? (string) tenant()->getTenantKey()
                : self::CENTRAL_VALUE,
            'options' => [
                $this->centralOption($request),
                ...$tenantOptions,
            ],
        ];
    }

    private function authorizedActor(Request $request): ?Authenticatable
    {
        if ($request->routeIs('tenant.*')) {
            return $this->impersonationContext->authorizedCentralUser($request);
        }

        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        return $this->permissions->userHas($user, 'tenants.update')
            ? $user
            : null;
    }

    /**
     * @return array{
     *     action:'central',
     *     label:string,
     *     logo_url:?string,
     *     meta:?string,
     *     method:'get',
     *     url:string,
     *     value:string
     * }
     */
    private function centralOption(Request $request): array
    {
        $branding = $this->centralBranding();
        $centralDomain = (string) (config('tenancy.central_domains')[0] ?? parse_url((string) config('app.url'), PHP_URL_HOST) ?? '');
        $leaveUrl = $this->routeExists('tenant.leave-impersonation')
            ? route('tenant.leave-impersonation')
            : $this->impersonationContext->centralAppUrl($request);

        return [
            'action' => 'central',
            'label' => $branding['name'],
            'logo_url' => $branding['logo_url'],
            'meta' => $branding['subtitle'] !== null && $branding['subtitle'] !== ''
                ? $branding['subtitle']
                : $centralDomain,
            'method' => 'get',
            'url' => $request->routeIs('tenant.*')
                ? $leaveUrl
                : $this->impersonationContext->centralAppUrl($request),
            'value' => self::CENTRAL_VALUE,
        ];
    }

    /**
     * @return array{
     *     action:'tenant',
     *     label:string,
     *     logo_url:null,
     *     meta:?string,
     *     method:'post',
     *     url:string,
     *     value:string
     * }
     */
    private function tenantOption(Model $tenant, string $routeName): array
    {
        $primaryDomain = $this->primaryDomain($tenant);
        $label = trim((string) $tenant->getAttribute('name'));

        return [
            'action' => 'tenant',
            'label' => $label !== '' ? $label : ($primaryDomain !== '' ? $primaryDomain : (string) $tenant->getKey()),
            'logo_url' => null,
            'meta' => $primaryDomain !== '' ? $primaryDomain : null,
            'method' => 'post',
            'url' => route($routeName, ['tenant' => $tenant->getKey()]),
            'value' => (string) $tenant->getKey(),
        ];
    }

    /**
     * @return array{logo_url:?string,name:string,subtitle:?string}
     */
    private function centralBranding(): array
    {
        $currentTenant = function_exists('tenancy') && tenancy()->initialized
            ? tenancy()->tenant
            : null;

        if ($currentTenant !== null) {
            tenancy()->end();
        }

        try {
            $publicSettings = $this->settings->public();
            $appName = data_get($publicSettings, 'general.app_name');
            $appSubtitle = data_get($publicSettings, 'general.app_subtitle');

            return [
                'logo_url' => $this->settingsLogo->currentUrl(),
                'name' => is_string($appName) && trim($appName) !== ''
                    ? trim($appName)
                    : (string) config('app.name'),
                'subtitle' => is_string($appSubtitle) && trim($appSubtitle) !== ''
                    ? trim($appSubtitle)
                    : null,
            ];
        } finally {
            if ($currentTenant instanceof TenantContract) {
                tenancy()->initialize($currentTenant);
            }
        }
    }

    private function impersonateRouteName(Request $request): ?string
    {
        $routeName = $request->routeIs('tenant.*')
            ? 'tenant.tenants.impersonate'
            : 'tenants.impersonate';

        return $this->routeExists($routeName)
            ? $routeName
            : null;
    }

    private function routeExists(string $name): bool
    {
        return app('router')->getRoutes()->getByName($name) !== null;
    }

    private function primaryDomain(Model $tenant): string
    {
        if ($tenant->relationLoaded('domains')) {
            $domains = $tenant->getRelation('domains');

            if ($domains instanceof EloquentCollection) {
                $domain = $domains
                    ->sortBy('id')
                    ->first()?->getAttribute('domain');

                return is_string($domain) ? trim($domain) : '';
            }
        }

        if (method_exists($tenant, 'domains')) {
            $domain = $tenant->domains()
                ->orderBy('id')
                ->value('domain');

            return is_string($domain) ? trim($domain) : '';
        }

        return '';
    }
}
