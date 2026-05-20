<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

final class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tenant = $this->resource;
        $domains = $this->domains($tenant);

        return [
            'id' => (string) $tenant->getKey(),
            'primary_domain' => (string) ($domains->first() ?? ''),
            'domains' => $domains->all(),
            'domains_count' => $tenant->getAttribute('domains_count') !== null
                ? (int) $tenant->getAttribute('domains_count')
                : $domains->count(),
            'database_name' => $this->databaseName($tenant),
            'name' => $this->nullableString($tenant->getAttribute('name')),
            'plan' => $this->nullableString($tenant->getAttribute('plan')),
            'status' => $this->nullableString($tenant->getAttribute('status')) ?? 'active',
            'super_admin' => $this->superAdmin($tenant),
            'created_at' => optional($tenant->getAttribute('created_at'))?->format(DATE_ATOM),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    private function domains(object $tenant): Collection
    {
        if (method_exists($tenant, 'relationLoaded') && $tenant->relationLoaded('domains')) {
            return $tenant->getRelation('domains')
                ->pluck('domain')
                ->filter(static fn (mixed $domain): bool => is_string($domain) && $domain !== '')
                ->values();
        }

        if (method_exists($tenant, 'domains')) {
            return $tenant->domains()
                ->pluck('domain')
                ->filter(static fn (mixed $domain): bool => is_string($domain) && $domain !== '')
                ->values();
        }

        return collect();
    }

    private function databaseName(object $tenant): string
    {
        $configured = $tenant->getAttribute('tenancy_db_name');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $prefix = (string) config('tenancy.database.prefix', 'tenant_');
        $suffix = (string) config('tenancy.database.suffix', '');

        return $prefix.$tenant->getKey().$suffix;
    }

    /**
     * @return array<string, string|null>|null
     */
    private function superAdmin(object $tenant): ?array
    {
        $firstName = $this->nullableString($tenant->getAttribute('super_admin_first_name'));
        $lastName = $this->nullableString($tenant->getAttribute('super_admin_last_name'));
        $email = $this->nullableString($tenant->getAttribute('super_admin_email'));
        $id = $this->nullableString($tenant->getAttribute('super_admin_user_id'));
        $mobile = $this->nullableString($tenant->getAttribute('super_admin_mobile'));

        if ($firstName === null && $lastName === null && $email === null && $id === null) {
            return null;
        }

        $fullName = trim(implode(' ', array_filter([$firstName, $lastName])));

        return [
            'email' => $email,
            'first_name' => $firstName,
            'full_name' => $fullName !== '' ? $fullName : null,
            'id' => $id,
            'last_name' => $lastName,
            'mobile' => $mobile,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
