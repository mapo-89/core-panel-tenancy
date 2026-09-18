<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Administration\SystemUpdates;

use CorePanel\Contracts\SystemUpdateSettingsAccess;
use Stancl\Tenancy\Tenancy;

final readonly class CentralSystemUpdateSettingsAccess implements SystemUpdateSettingsAccess
{
    public function __construct(private Tenancy $tenancy) {}

    public function allows(): bool
    {
        return ! $this->tenancy->initialized;
    }
}
