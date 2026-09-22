<?php

declare(strict_types=1);

namespace CorePanelTenancy\Support\Horizon;

use CorePanel\Contracts\HorizonAccess;
use CorePanel\Support\Horizon\DefaultHorizonAccess;

final readonly class TenantAwareHorizonAccess implements HorizonAccess
{
    public function __construct(private DefaultHorizonAccess $default) {}

    public function allows(mixed $user): bool
    {
        return ! tenancy()->initialized && $this->default->allows($user);
    }
}
