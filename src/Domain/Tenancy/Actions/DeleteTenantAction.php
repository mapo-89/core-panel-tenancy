<?php

declare(strict_types=1);

namespace CorePanelTenancy\Domain\Tenancy\Actions;

use Illuminate\Database\Eloquent\Model;

final class DeleteTenantAction
{
    public function execute(Model $tenant): bool
    {
        return (bool) $tenant->delete();
    }
}
