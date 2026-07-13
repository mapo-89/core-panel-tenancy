<?php

declare(strict_types=1);

namespace CorePanelTenancy\Domains\Tenancy\Actions;

use CorePanelTenancy\Support\TenantModelResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

final class UpsertTenantSuperAdminAction
{
    public function __construct(private readonly TenantModelResolver $models) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Model $tenant, array $data): ?Authenticatable
    {
        if (! $tenant instanceof TenantContract) {
            return null;
        }

        $userModel = $this->models->userModelClass();

        try {
            tenancy()->initialize($tenant);

            if (! Schema::hasTable('users')) {
                return null;
            }

            $userColumns = Schema::getColumnListing('users');

            /** @var Model&Authenticatable $tenantUser */
            $tenantUser = $this->resolveExistingTenantSuperAdmin($tenant, $data, $userModel)
                ?? new $userModel;

            $attributes = [
                'first_name' => (string) $data['first_name'],
                'last_name' => (string) $data['last_name'],
                'email' => (string) $data['email'],
                'status' => 'active',
                'email_verified_at' => $tenantUser->getAttribute('email_verified_at') ?? now(),
            ];

            if (in_array('mobile', $userColumns, true)) {
                $attributes['mobile'] = Arr::get($data, 'mobile');
            }

            if (in_array('requires_password_setup', $userColumns, true)) {
                $attributes['requires_password_setup'] = false;
            }

            $tenantUser->forceFill($attributes);

            if (filled($data['password'] ?? null)) {
                $tenantUser->setAttribute('password', Hash::make((string) $data['password']));
            } elseif (! $tenantUser->exists) {
                $tenantUser->setAttribute('password', Hash::make(Str::password(32)));
            }

            $tenantUser->save();

            if (Schema::hasTable('roles') && Schema::hasTable('model_has_roles') && method_exists($tenantUser, 'syncRoles')) {
                Role::findOrCreate('super-admin', 'web');
                $tenantUser->syncRoles(['super-admin']);
            }

            $tenant->update([
                'super_admin_user_id' => (string) $tenantUser->getAuthIdentifier(),
                'super_admin_first_name' => $tenantUser->getAttribute('first_name'),
                'super_admin_last_name' => $tenantUser->getAttribute('last_name'),
                'super_admin_email' => $tenantUser->getAttribute('email'),
                'super_admin_mobile' => $tenantUser->getAttribute('mobile'),
            ]);

            return $tenantUser;
        } catch (QueryException) {
            return null;
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  class-string<Model&Authenticatable>  $userModel
     * @return (Model&Authenticatable)|null
     */
    private function resolveExistingTenantSuperAdmin(Model $tenant, array $data, string $userModel): ?Model
    {
        $superAdminUserId = $tenant->getAttribute('super_admin_user_id');

        if (is_string($superAdminUserId) && $superAdminUserId !== '') {
            $existingUser = $userModel::query()->find($superAdminUserId);

            if ($existingUser instanceof Model && $existingUser instanceof Authenticatable) {
                return $existingUser;
            }
        }

        $email = Arr::get($data, 'email', $tenant->getAttribute('super_admin_email'));

        if (is_string($email) && $email !== '') {
            $existingUser = $userModel::query()->where('email', $email)->first();

            if ($existingUser instanceof Model && $existingUser instanceof Authenticatable) {
                return $existingUser;
            }
        }

        if (method_exists($userModel, 'role')) {
            $fallbackUser = $userModel::role('super-admin')->orderBy('created_at')->first();

            if ($fallbackUser instanceof Model && $fallbackUser instanceof Authenticatable) {
                return $fallbackUser;
            }
        }

        return null;
    }
}
