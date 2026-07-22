<?php

declare(strict_types=1);

namespace CorePanelTenancy\Domain\Tenancy\Policies;

use CorePanel\Support\Permissions\PermissionService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final readonly class TenantPolicy
{
    public function __construct(private PermissionService $permissions) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->permissions->userHas($user, 'tenants.view');
    }

    public function view(Authenticatable $user, Model $tenant): bool
    {
        return $this->permissions->userHas($user, 'tenants.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->permissions->userHas($user, 'tenants.create');
    }

    public function update(Authenticatable $user, Model $tenant): bool
    {
        return $this->permissions->userHas($user, 'tenants.update');
    }

    public function delete(Authenticatable $user, Model $tenant): bool
    {
        return $this->permissions->userHas($user, 'tenants.delete');
    }
}
