<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Requests;

use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'primary_domain' => ['required', 'string', 'max:255'],
            'additional_domains' => ['nullable', 'string', 'max:2000'],
            'tenant_id' => ['nullable', 'string', 'max:255', 'alpha_dash'],
            'database_name' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:100'],
            'super_admin_first_name' => ['required', 'string', 'max:255'],
            'super_admin_last_name' => ['required', 'string', 'max:255'],
            'super_admin_email' => ['required', 'string', 'email', 'max:255'],
            'super_admin_mobile' => ['nullable', 'string', 'max:255'],
            'super_admin_password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<int, \Closure(): void>
     */
    public function after(): array
    {
        return [
            function (): void {
                $domains = $this->domains();

                if ($domains->isEmpty()) {
                    return;
                }

                $centralDomains = collect((array) config('tenancy.central_domains', []))
                    ->map(static fn (string $domain): string => Str::lower(trim($domain)))
                    ->filter();

                if ($domains->intersect($centralDomains)->isNotEmpty()) {
                    $this->validator->errors()->add(
                        'primary_domain',
                        __('core-panel-tenancy::tenancy.validation.reserved_domains', [
                            'domains' => $domains->intersect($centralDomains)->implode(', '),
                        ]),
                    );
                }

                $domainModel = app(TenantModelResolver::class)->domainModelClass();
                $existingDomains = $domainModel::query()
                    ->whereIn('domain', $domains->all())
                    ->pluck('domain');

                if ($existingDomains->isNotEmpty()) {
                    $this->validator->errors()->add(
                        'primary_domain',
                        __('core-panel-tenancy::tenancy.validation.assigned_domains', [
                            'domains' => $existingDomains->implode(', '),
                        ]),
                    );
                }

                $tenantId = $this->resolvedTenantId();

                if ($tenantId === null) {
                    $this->validator->errors()->add(
                        'tenant_id',
                        __('core-panel-tenancy::tenancy.validation.invalid_tenant_id'),
                    );

                    return;
                }

                $tenantModel = app(TenantModelResolver::class)->tenantModelClass();

                if ($tenantModel::query()->whereKey($tenantId)->exists()) {
                    $this->validator->errors()->add('tenant_id', __('validation.unique', [
                        'attribute' => __('validation.attributes.tenant_id'),
                    ]));
                }
            },
        ];
    }

    /**
     * @return Collection<int, lowercase-string&non-empty-string>
     */
    public function domains(): Collection
    {
        $allDomains = collect([
            $this->string('primary_domain')->toString(),
            $this->string('additional_domains')->toString(),
        ])->implode("\n");

        $domains = [];

        foreach (preg_split('/[\s,;]+/', $allDomains) ?: [] as $domain) {
            $normalizedDomain = Str::lower(trim($domain));

            if ($normalizedDomain === '' || in_array($normalizedDomain, $domains, true)) {
                continue;
            }

            $domains[] = $normalizedDomain;
        }

        return collect(array_map(static fn (string $domain): string => $domain, $domains));
    }

    public function resolvedTenantId(): ?string
    {
        $source = $this->filled('tenant_id')
            ? $this->string('tenant_id')->trim()->toString()
            : $this->tenantIdFromPrimaryDomain();

        if ($source === null || $source === '') {
            return null;
        }

        $tenantId = Str::slug($source);

        return $tenantId !== '' ? $tenantId : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantData(): array
    {
        return Arr::whereNotNull([
            'name' => $this->filled('name') ? $this->string('name')->trim()->toString() : null,
            'plan' => $this->filled('plan') ? $this->string('plan')->trim()->toString() : null,
            'status' => 'active',
        ]);
    }

    public function databaseName(): ?string
    {
        if (! $this->filled('database_name')) {
            return null;
        }

        $databaseName = $this->string('database_name')->trim()->toString();

        return $databaseName !== '' ? $databaseName : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantSuperAdminData(): array
    {
        return [
            'first_name' => $this->string('super_admin_first_name')->trim()->toString(),
            'last_name' => $this->string('super_admin_last_name')->trim()->toString(),
            'email' => Str::lower($this->string('super_admin_email')->trim()->toString()),
            'mobile' => $this->filled('super_admin_mobile') ? $this->string('super_admin_mobile')->trim()->toString() : null,
            'password' => $this->string('super_admin_password')->toString(),
        ];
    }

    private function tenantIdFromPrimaryDomain(): ?string
    {
        $primaryDomain = $this->domains()->first();

        if (! is_string($primaryDomain)) {
            return null;
        }

        $centralDomains = collect((array) config('tenancy.central_domains', []))
            ->map(static fn (string $domain): string => Str::lower(trim($domain)))
            ->filter()
            ->sortByDesc(static fn (string $domain): int => strlen($domain))
            ->values();

        foreach ($centralDomains as $centralDomain) {
            if ($primaryDomain === $centralDomain) {
                return null;
            }

            $suffix = '.'.$centralDomain;

            if (Str::endsWith($primaryDomain, $suffix)) {
                return Str::beforeLast($primaryDomain, $suffix);
            }
        }

        return $primaryDomain;
    }
}
