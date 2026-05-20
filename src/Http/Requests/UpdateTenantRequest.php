<?php

declare(strict_types=1);

namespace CorePanelTenancy\Http\Requests;

use App\Models\User;
use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateTenantRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'plan' => ['nullable', 'string', 'max:100'],
            'super_admin_first_name' => [$this->superAdminRequiredRule(), 'string', 'max:255'],
            'super_admin_last_name' => [$this->superAdminRequiredRule(), 'string', 'max:255'],
            'super_admin_email' => [$this->superAdminRequiredRule(), 'string', 'email', 'max:255'],
            'super_admin_mobile' => ['nullable', 'string', 'max:255'],
            'super_admin_password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<int, \Closure(): void>
     */
    public function after(): array
    {
        return [
            function (): void {
                $tenant = $this->tenant();

                if ($tenant === null) {
                    return;
                }

                $domains = $this->domains();
                $centralDomains = collect((array) config('tenancy.central_domains', []))
                    ->map(static fn (string $domain): string => Str::lower(trim($domain)))
                    ->filter();
                $reservedDomains = $domains->intersect($centralDomains);

                if ($reservedDomains->isNotEmpty()) {
                    $this->validator->errors()->add(
                        'primary_domain',
                        __('core-panel-tenancy::tenancy.validation.reserved_domains', [
                            'domains' => $reservedDomains->implode(', '),
                        ]),
                    );
                }

                $domainModel = app(TenantModelResolver::class)->domainModelClass();
                $existingDomains = $domainModel::query()
                    ->whereIn('domain', $domains->all())
                    ->where('tenant_id', '!=', (string) $tenant->getKey())
                    ->pluck('domain');

                if ($existingDomains->isNotEmpty()) {
                    $this->validator->errors()->add(
                        'primary_domain',
                        __('core-panel-tenancy::tenancy.validation.assigned_domains', [
                            'domains' => $existingDomains->implode(', '),
                        ]),
                    );
                }

                if ($this->hasConflictingTenantSuperAdminEmail()) {
                    $this->validator->errors()->add(
                        'super_admin_email',
                        __('validation.unique', ['attribute' => __('validation.attributes.email')]),
                    );
                }
            },
        ];
    }

    /**
     * @return Collection<int, string>
     */
    public function domains(): Collection
    {
        $allDomains = collect([
            $this->string('primary_domain')->toString(),
            $this->string('additional_domains')->toString(),
        ])->implode("\n");

        return collect(preg_split('/[\s,;]+/', $allDomains) ?: [])
            ->map(static fn (string $domain): string => Str::lower(trim($domain)))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantData(): array
    {
        return Arr::whereNotNull([
            'name' => $this->filled('name') ? $this->string('name')->trim()->toString() : null,
            'plan' => $this->filled('plan') ? $this->string('plan')->trim()->toString() : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantSuperAdminData(): array
    {
        return Arr::whereNotNull([
            'first_name' => $this->filled('super_admin_first_name') ? $this->string('super_admin_first_name')->trim()->toString() : null,
            'last_name' => $this->filled('super_admin_last_name') ? $this->string('super_admin_last_name')->trim()->toString() : null,
            'email' => $this->filled('super_admin_email') ? Str::lower($this->string('super_admin_email')->trim()->toString()) : null,
            'mobile' => $this->filled('super_admin_mobile') ? $this->string('super_admin_mobile')->trim()->toString() : null,
            'password' => $this->filled('super_admin_password') ? $this->string('super_admin_password')->toString() : null,
        ]);
    }

    public function shouldManageTenantSuperAdmin(): bool
    {
        if ($this->currentSuperAdminUserId() !== null) {
            return true;
        }

        return collect([
            'super_admin_first_name',
            'super_admin_last_name',
            'super_admin_email',
            'super_admin_mobile',
            'super_admin_password',
        ])->contains(fn (string $field): bool => $this->filled($field));
    }

    private function tenant(): ?object
    {
        $tenantId = $this->route('tenant');

        if (! is_scalar($tenantId) || $tenantId === '') {
            return null;
        }

        $tenantModel = app(TenantModelResolver::class)->tenantModelClass();

        return $tenantModel::query()->find((string) $tenantId);
    }

    private function superAdminRequiredRule(): object
    {
        return Rule::requiredIf(fn (): bool => $this->shouldManageTenantSuperAdmin());
    }

    private function currentSuperAdminUserId(): ?string
    {
        $tenant = $this->tenant();

        if (! $tenant instanceof Model) {
            return null;
        }

        try {
            tenancy()->initialize($tenant);

            return $this->resolveCurrentSuperAdminUserIdInTenantContext($tenant);
        } catch (QueryException) {
            return null;
        } finally {
            tenancy()->end();
        }
    }

    private function hasConflictingTenantSuperAdminEmail(): bool
    {
        if (! $this->filled('super_admin_email')) {
            return false;
        }

        $tenant = $this->tenant();

        if (! $tenant instanceof Model) {
            return false;
        }

        try {
            tenancy()->initialize($tenant);

            if (! Schema::hasTable('users')) {
                return false;
            }

            $userModel = $this->userModelClass();

            return $userModel::query()
                ->where('email', Str::lower($this->string('super_admin_email')->trim()->toString()))
                ->when(
                    $this->resolveCurrentSuperAdminUserIdInTenantContext($tenant),
                    fn ($query, string $userId) => $query->whereKeyNot($userId),
                )
                ->exists();
        } catch (QueryException) {
            return false;
        } finally {
            tenancy()->end();
        }
    }

    private function resolveCurrentSuperAdminUserIdInTenantContext(Model $tenant): ?string
    {
        $userModel = $this->userModelClass();
        $superAdminUserId = $tenant->getAttribute('super_admin_user_id');

        if (is_string($superAdminUserId) && $superAdminUserId !== '') {
            $existingUser = $userModel::query()->find($superAdminUserId);

            if ($existingUser instanceof Authenticatable) {
                return (string) $existingUser->getAuthIdentifier();
            }
        }

        $superAdminEmail = $tenant->getAttribute('super_admin_email');

        if (is_string($superAdminEmail) && $superAdminEmail !== '') {
            $existingUser = $userModel::query()->where('email', $superAdminEmail)->first();

            if ($existingUser instanceof Authenticatable) {
                return (string) $existingUser->getAuthIdentifier();
            }
        }

        if (method_exists($userModel, 'role')) {
            $fallbackUser = $userModel::role('super-admin')->orderBy('created_at')->first();

            if ($fallbackUser instanceof Authenticatable) {
                return (string) $fallbackUser->getAuthIdentifier();
            }
        }

        return null;
    }

    /**
     * @return class-string<Model&Authenticatable>
     */
    private function userModelClass(): string
    {
        /** @var class-string<Model&Authenticatable>|null $modelClass */
        $modelClass = config('core-panel.user_model');

        if (is_string($modelClass) && $modelClass !== '' && class_exists($modelClass)) {
            return $modelClass;
        }

        /** @var class-string<Model&Authenticatable> $fallback */
        $fallback = User::class;

        return $fallback;
    }
}
