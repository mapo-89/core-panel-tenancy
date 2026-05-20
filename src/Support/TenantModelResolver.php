<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Models\Domain;

final class TenantModelResolver
{
    /**
     * @return class-string<Model>
     */
    public function tenantModelClass(): string
    {
        /** @var class-string<Model>|null $modelClass */
        $modelClass = config('tenancy.tenant_model');

        if (is_string($modelClass) && $modelClass !== '' && class_exists($modelClass)) {
            return $modelClass;
        }

        /** @var class-string<Model> $fallback */
        $fallback = Tenant::class;

        return $fallback;
    }

    /**
     * @return class-string<Model>
     */
    public function domainModelClass(): string
    {
        /** @var class-string<Model>|null $modelClass */
        $modelClass = config('tenancy.domain_model');

        if (is_string($modelClass) && $modelClass !== '' && class_exists($modelClass)) {
            return $modelClass;
        }

        /** @var class-string<Model> $fallback */
        $fallback = Domain::class;

        return $fallback;
    }
}
