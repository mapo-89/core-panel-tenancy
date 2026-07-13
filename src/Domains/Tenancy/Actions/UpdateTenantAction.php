<?php

declare(strict_types=1);

namespace CorePanelTenancy\Domains\Tenancy\Actions;

use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

final class UpdateTenantAction
{
    public function __construct(private readonly TenantModelResolver $models) {}

    /**
     * @param  list<string>  $domains
     * @param  array<string, mixed>  $data
     */
    public function execute(Model $tenant, array $domains, array $data = []): Model&TenantContract
    {
        $normalizedDomains = $this->normalizeDomains($domains);
        $tenant = $this->requireTenantContract($tenant);
        $this->ensureDomainsAreAvailable($tenant, $normalizedDomains);
        $domainModel = $this->models->domainModelClass();

        if ($data !== []) {
            $tenant->update($data);
        }

        $currentDomains = $domainModel::query()
            ->where('tenant_id', (string) $tenant->getKey())
            ->pluck('domain');
        $domainsToAdd = $normalizedDomains->diff($currentDomains);
        $domainsToRemove = $currentDomains->diff($normalizedDomains);

        if ($domainsToRemove->isNotEmpty()) {
            $domainModel::query()
                ->where('tenant_id', (string) $tenant->getKey())
                ->whereIn('domain', $domainsToRemove->all())
                ->delete();
        }

        if ($domainsToAdd->isNotEmpty()) {
            foreach ($domainsToAdd as $domain) {
                $domainModel::query()->create([
                    'tenant_id' => (string) $tenant->getKey(),
                    'domain' => $domain,
                ]);
            }
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
     * @param  Collection<int, lowercase-string&non-empty-string>  $domains
     */
    private function ensureDomainsAreAvailable(Model $tenant, Collection $domains): void
    {
        $domainModel = $this->models->domainModelClass();
        $currentDomains = $domainModel::query()
            ->where('tenant_id', (string) $tenant->getKey())
            ->pluck('domain');
        $existingDomains = $domainModel::query()
            ->whereIn('domain', $domains->all())
            ->whereNotIn('domain', $currentDomains->all())
            ->pluck('domain');

        if ($existingDomains->isNotEmpty()) {
            $domainList = $existingDomains->implode(', ');

            throw new InvalidArgumentException("The following domains are already assigned: {$domainList}.");
        }
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
}
