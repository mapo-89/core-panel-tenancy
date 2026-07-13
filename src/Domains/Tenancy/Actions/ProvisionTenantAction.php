<?php

declare(strict_types=1);

namespace CorePanelTenancy\Domains\Tenancy\Actions;

use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

final readonly class ProvisionTenantAction
{
    public function __construct(private TenantModelResolver $models) {}

    /**
     * @param  list<string>  $domains
     * @param  array<string, mixed>  $data
     */
    public function execute(array $domains, ?string $tenantId = null, ?string $databaseName = null, array $data = []): Model&TenantContract
    {
        $normalizedDomains = $this->normalizeDomains($domains);
        $resolvedTenantId = $this->resolveTenantId($tenantId, (string) $normalizedDomains->first());
        $this->ensureTenantIdIsAvailable($resolvedTenantId);
        $this->ensureDomainsAreAvailable($normalizedDomains);

        $tenantAttributes = ['id' => $resolvedTenantId, ...$data];
        $tenantModel = $this->models->tenantModelClass();
        $resolvedDatabaseName = is_string($databaseName) ? trim($databaseName) : null;

        if ($resolvedDatabaseName !== null && $resolvedDatabaseName !== '') {
            $tenantAttributes['tenancy_db_name'] = $resolvedDatabaseName;
        }

        $tenant = $tenantModel::query()->create($tenantAttributes);
        $domainModel = $this->models->domainModelClass();
        $tenant = $this->requireTenantContract($tenant);

        foreach ($normalizedDomains as $domain) {
            $domainModel::query()->create([
                'tenant_id' => (string) $tenant->getKey(),
                'domain' => $domain,
            ]);
        }

        $tenant->setRelation(
            'domains',
            $domainModel::query()
                ->where('tenant_id', (string) $tenant->getKey())
                ->orderBy('id')
                ->get(),
        );

        return $tenant;
    }

    /**
     * @param  list<string>  $domains
     * @return Collection<int, lowercase-string&non-empty-string>
     */
    private function normalizeDomains(array $domains): Collection
    {
        $normalizedDomains = [];

        foreach ($domains as $domain) {
            $normalizedDomain = Str::lower(trim($domain));

            if ($normalizedDomain === '' || in_array($normalizedDomain, $normalizedDomains, true)) {
                continue;
            }

            $normalizedDomains[] = $normalizedDomain;
        }

        if ($normalizedDomains === []) {
            throw new InvalidArgumentException('At least one tenant domain is required.');
        }

        return collect(array_map(static fn (string $domain): string => $domain, $normalizedDomains));
    }

    /**
     * @return Model&TenantContract
     */
    private function requireTenantContract(Model $tenant): Model
    {
        if (! $tenant instanceof TenantContract) {
            throw new InvalidArgumentException('Configured tenant model must implement the tenancy tenant contract.');
        }

        return $tenant;
    }

    private function resolveTenantId(?string $tenantId, string $primaryDomain): string
    {
        $resolvedTenantId = Str::slug(
            $tenantId !== null && trim($tenantId) !== ''
                ? trim($tenantId)
                : $this->tenantIdFromPrimaryDomain($primaryDomain),
        );

        if ($resolvedTenantId === '') {
            throw new InvalidArgumentException('The tenant id could not be derived from the provided input.');
        }

        return $resolvedTenantId;
    }

    private function ensureTenantIdIsAvailable(string $tenantId): void
    {
        $tenantModel = $this->models->tenantModelClass();

        if ($tenantModel::query()->whereKey($tenantId)->exists()) {
            throw new InvalidArgumentException("A tenant with the id [{$tenantId}] already exists.");
        }
    }

    /**
     * @param  Collection<int, lowercase-string&non-empty-string>  $domains
     */
    private function ensureDomainsAreAvailable(Collection $domains): void
    {
        $domainModel = $this->models->domainModelClass();
        $existingDomains = $domainModel::query()
            ->whereIn('domain', $domains->all())
            ->pluck('domain');

        if ($existingDomains->isNotEmpty()) {
            $domainList = $existingDomains->implode(', ');

            throw new InvalidArgumentException("The following domains are already assigned: {$domainList}.");
        }
    }

    private function tenantIdFromPrimaryDomain(string $primaryDomain): string
    {
        $centralDomains = collect((array) config('tenancy.central_domains', []))
            ->map(static fn (string $domain): string => Str::lower(trim($domain)))
            ->filter()
            ->sortByDesc(static fn (string $domain): int => strlen($domain))
            ->values();

        foreach ($centralDomains as $centralDomain) {
            if ($primaryDomain === $centralDomain) {
                return $primaryDomain;
            }

            $suffix = '.'.$centralDomain;

            if (Str::endsWith($primaryDomain, $suffix)) {
                return Str::beforeLast($primaryDomain, $suffix);
            }
        }

        return $primaryDomain;
    }
}
