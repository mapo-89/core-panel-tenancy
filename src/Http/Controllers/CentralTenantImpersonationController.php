<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Controllers;

use CorePanel\Support\Permissions\PermissionService;
use CorePanelTenancy\Support\Tenancy\CentralImpersonationContext;
use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Symfony\Component\HttpFoundation\Response;

final class CentralTenantImpersonationController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly TenantModelResolver $tenants,
    ) {}

    public function __invoke(Request $request, string $tenant): Response
    {
        $actor = $this->impersonationActor($request);

        abort_unless($actor !== null, 403);

        $record = $this->findTenantOrFail($tenant);
        $superAdminUserId = trim((string) $record->getAttribute('super_admin_user_id'));

        if ($superAdminUserId === '') {
            return back()->with('error', __('core-panel-tenancy::page-tenants.impersonation_unavailable'));
        }

        $tenantDomain = $this->primaryDomain($record);

        if ($tenantDomain === '') {
            return back()->with('error', __('core-panel-tenancy::page-tenants.impersonation_domain_missing'));
        }

        $token = ImpersonationToken::create([
            'tenant_id' => (string) $record->getTenantKey(),
            'user_id' => $superAdminUserId,
            'redirect_url' => route('tenant.core-panel.dashboard', absolute: false),
            'auth_guard' => config('auth.defaults.guard'),
        ]);

        $tokenValue = $token->getAttribute('token');

        abort_unless(is_string($tokenValue) && $tokenValue !== '', 500);

        $url = $this->tenantImpersonationUrl(
            $request,
            $tenantDomain,
            $tokenValue,
            app(CentralImpersonationContext::class)->encryptedPayload($actor),
        );

        if ($request->header('X-Inertia')) {
            return Inertia::location($url);
        }

        return redirect()->away($url);
    }

    private function impersonationActor(Request $request): ?Authenticatable
    {
        if ($request->routeIs('tenant.*')) {
            return app(CentralImpersonationContext::class)->authorizedCentralUser($request);
        }

        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        return $this->permissions->userHas($user, 'tenants.update')
            ? $user
            : null;
    }

    private function findTenantOrFail(string $tenant): object
    {
        $tenantModel = $this->tenants->tenantModelClass();

        return $tenantModel::query()
            ->with('domains')
            ->findOrFail($tenant);
    }

    private function primaryDomain(object $tenant): string
    {
        if (method_exists($tenant, 'domains')) {
            $domain = $tenant->domains()
                ->orderBy('id')
                ->value('domain');

            return is_string($domain) ? trim($domain) : '';
        }

        return '';
    }

    private function tenantImpersonationUrl(
        Request $request,
        string $tenantDomain,
        string $token,
        string $context,
    ): string {
        $port = $request->getPort();
        $host = $tenantDomain;

        if (! in_array($port, [80, 443], true) && ! str_contains($tenantDomain, ':')) {
            $host .= ':'.$port;
        }

        return sprintf(
            '%s://%s%s?%s=%s',
            $request->getScheme(),
            $host,
            route('tenant.impersonate', ['token' => $token], false),
            CentralImpersonationContext::QUERY_KEY,
            urlencode($context),
        );
    }
}
