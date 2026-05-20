<?php

declare(strict_types=1);

namespace CorePanelTenancy\Domains\Tenancy\Actions;

use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ProvisionTenantAction
{
    public function __construct(private TenantModelResolver $models) {}

    /**
     * @param  list<string>  $domains
     * @param  array<string, mixed>  $data
     */
    public function execute(array $domains, ?string $tenantId = null, ?string $databaseName = null, array $data = []): Model
    {
        $normalizedDomains = $this->normalizeDomains($domains);
        $resolvedTenantId = $this->resolveTenantId($tenantId, (string) $normalizedDomains->first());
        $this->ensureTenantIdIsAvailable($resolvedTenantId);
        $this->ensureDomainsAreAvailable($normalizedDomains);

        $tenantModel = $this->models->tenantModelClass();
        /** @var Model $tenant */
        $tenantAttributes = ['id' => $resolvedTenantId, ...$data];
        $resolvedDatabaseName = is_string($databaseName) ? trim($databaseName) : null;

        if ($resolvedDatabaseName !== null && $resolvedDatabaseName !== '') {
            $tenantAttributes['tenancy_db_name'] = $resolvedDatabaseName;
        }

        $tenant = $tenantModel::query()->create($tenantAttributes);

        $tenant->domains()->createMany(
            $normalizedDomains
                ->map(static fn (string $domain): array => ['domain' => $domain])
                ->all(),
        );

        return $tenant->fresh('domains');
    }

    /**
     * @param  list<string>  $domains
     * @return Collection<int, string>
     */
    private function normalizeDomains(array $domains): Collection
    {
        $normalizedDomains = collect($domains)
            ->map(static fn (string $domain): string => Str::lower(trim($domain)))
            ->filter()
            ->unique()
            ->values();

        if ($normalizedDomains->isEmpty()) {
            throw new InvalidArgumentException('At least one tenant domain is required.');
        }

        return $normalizedDomains;
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
     * @param  Collection<int, string>  $domains
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
