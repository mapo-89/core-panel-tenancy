<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\Tenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InitializeTenancyFromRequestDomain
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (tenancy()->initialized) {
            return $next($request);
        }

        $host = Str::lower(trim($request->getHost()));
        $this->scopeSessionCookieForCentralContext();

        if ($host === '' || $this->isCentralDomain($host)) {
            return $next($request);
        }

        $tenant = $this->resolveTenantForHost($host);

        if (! $tenant instanceof Tenant) {
            throw new NotFoundHttpException;
        }

        $this->scopeSessionCookieForTenant($tenant);
        tenancy()->initialize($tenant);

        try {
            return $next($request);
        } finally {
            tenancy()->end();
        }
    }

    private function isCentralDomain(string $host): bool
    {
        $centralDomains = collect((array) config('tenancy.central_domains', []))
            ->map(static fn (string $domain): string => Str::lower(trim($domain)))
            ->filter()
            ->all();

        return in_array($host, $centralDomains, true);
    }

    private function resolveTenantForHost(string $host): ?Tenant
    {
        /** @var class-string<Model>|null $tenantModel */
        $tenantModel = config('tenancy.tenant_model');

        if (! is_string($tenantModel) || $tenantModel === '' || ! class_exists($tenantModel)) {
            return null;
        }

        $tenant = $tenantModel::query()
            ->whereHas('domains', static function ($query) use ($host): void {
                $query->where('domain', $host);
            })
            ->first();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    private function scopeSessionCookieForCentralContext(): void
    {
        $baseCookie = (string) config('session.cookie', 'laravel_session');

        config()->set('session.cookie', $baseCookie.'_central');
    }

    private function scopeSessionCookieForTenant(Tenant $tenant): void
    {
        $baseCookie = (string) config('session.cookie', 'laravel_session');
        $tenantKey = Str::slug((string) $tenant->getTenantKey(), '_');
        $tenantSuffix = $tenantKey !== '' ? $tenantKey : 'tenant';

        config()->set('session.cookie', $baseCookie.'_tenant_'.$tenantSuffix);
    }
}
