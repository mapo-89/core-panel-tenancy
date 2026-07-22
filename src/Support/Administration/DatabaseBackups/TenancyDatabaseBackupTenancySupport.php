<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Administration\DatabaseBackups;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;

final class TenancyDatabaseBackupTenancySupport
{
    public function centralConnectionName(): string
    {
        $connection = config('tenancy.database.central_connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : (string) config('database.default');
    }

    public function enabledForBackups(): bool
    {
        return function_exists('tenancy') && $this->tenantModelClass() !== null;
    }

    public function findTenant(string $tenantId): ?object
    {
        $tenantModel = $this->tenantModelClass();

        if ($tenantModel === null) {
            return null;
        }

        $tenant = $tenantModel::query()->find($tenantId);

        return is_object($tenant) ? $tenant : null;
    }

    /**
     * @return Collection<int, object>
     */
    public function tenants(): Collection
    {
        $tenantModel = $this->tenantModelClass();

        if ($tenantModel === null) {
            return collect();
        }

        $query = $tenantModel::query();

        try {
            return $query->with('domains')->orderBy($query->getModel()->getKeyName())->get();
        } catch (Throwable) {
            return $query->orderBy($query->getModel()->getKeyName())->get();
        }
    }

    public function tenantId(object $tenant): string
    {
        if ($tenant instanceof Model) {
            return (string) $tenant->getKey();
        }

        return (string) data_get($tenant, 'id', '');
    }

    public function tenantLabel(object $tenant): string
    {
        $primaryDomain = data_get($tenant, 'primary_domain');

        if (is_string($primaryDomain) && trim($primaryDomain) !== '') {
            return trim($primaryDomain);
        }

        $domains = data_get($tenant, 'domains');

        if ($domains instanceof Collection) {
            $domain = $domains->sortBy('id')->first();
            $value = data_get($domain, 'domain');

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->tenantId($tenant);
    }

    public function runForTenant(object $tenant, Closure $callback): mixed
    {
        if (method_exists($tenant, 'run')) {
            return $tenant->run($callback);
        }

        $manager = tenancy();
        $previousTenant = $manager->initialized ? $manager->tenant : null;

        $manager->initialize($tenant);

        try {
            return $callback();
        } finally {
            if ($previousTenant !== null) {
                $manager->initialize($previousTenant);
            } else {
                $manager->end();
            }
        }
    }

    private function tenantModelClass(): ?string
    {
        $model = config('tenancy.tenant_model');

        return is_string($model) && $model !== '' && class_exists($model)
            ? $model
            : null;
    }
}
