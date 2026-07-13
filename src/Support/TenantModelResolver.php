<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as FoundationUser;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Models\Tenant;

final class TenantModelResolver
{
    /**
     * @return class-string<Model&TenantContract>
     */
    public function tenantModelClass(): string
    {
        $configuredModelClass = config('tenancy.tenant_model');

        if (is_string($configuredModelClass) && $configuredModelClass !== '' && class_exists($configuredModelClass)) {
            /** @var class-string<Model&TenantContract> $modelClass */
            $modelClass = $configuredModelClass;

            return $modelClass;
        }

        /** @var class-string<Model&TenantContract> $fallback */
        $fallback = Tenant::class;

        return $fallback;
    }

    /**
     * @return class-string<Model>
     */
    public function domainModelClass(): string
    {
        $configuredModelClass = config('tenancy.domain_model');

        if (is_string($configuredModelClass) && $configuredModelClass !== '' && class_exists($configuredModelClass)) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $configuredModelClass;

            return $modelClass;
        }

        /** @var class-string<Model> $fallback */
        $fallback = Domain::class;

        return $fallback;
    }

    /**
     * @return class-string<Model&Authenticatable>
     */
    public function userModelClass(): string
    {
        $configuredModelClass = config('core-panel.user_model', config('auth.providers.users.model'));

        if (is_string($configuredModelClass) && $configuredModelClass !== '' && class_exists($configuredModelClass)) {
            /** @var class-string<Model&Authenticatable> $modelClass */
            $modelClass = $configuredModelClass;

            return $modelClass;
        }

        /** @var class-string<Model&Authenticatable> $fallback */
        $fallback = FoundationUser::class;

        return $fallback;
    }
}
