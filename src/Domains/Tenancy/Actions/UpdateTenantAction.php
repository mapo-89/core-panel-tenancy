<?php

declare(strict_types=1);

namespace CorePanelTenancy\Domains\Tenancy\Actions;

use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class UpdateTenantAction
{
    public function __construct(private readonly TenantModelResolver $models) {}

    /**
     * @param  list<string>  $domains
     * @param  array<string, mixed>  $data
     */
    public function execute(Model $tenant, array $domains, array $data = []): Model
    {
        $normalizedDomains = $this->normalizeDomains($domains);
        $this->ensureDomainsAreAvailable($tenant, $normalizedDomains);

        if ($data !== []) {
            $tenant->update($data);
        }

        $currentDomains = collect($tenant->domains()->pluck('domain'));
        $domainsToAdd = $normalizedDomains->diff($currentDomains);
        $domainsToRemove = $currentDomains->diff($normalizedDomains);

        if ($domainsToRemove->isNotEmpty()) {
            $domainModel = $this->models->domainModelClass();
            $domainModel::query()
                ->whereIn('domain', $domainsToRemove->all())
                ->delete();
        }

        if ($domainsToAdd->isNotEmpty()) {
            $tenant->domains()->createMany(
                $domainsToAdd->map(static fn (string $domain): array => ['domain' => $domain])->all(),
            );
        }

        return $tenant->fresh('domains');
    }

    /**
     * @param  Collection<int, string>  $domains
     */
    private function ensureDomainsAreAvailable(Model $tenant, Collection $domains): void
    {
        $currentDomains = collect($tenant->domains()->pluck('domain'));
        $domainModel = $this->models->domainModelClass();
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
}
