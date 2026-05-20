<?php

declare(strict_types=1);

return [
    'install' => [
        'description' => 'Installs the stancl tenancy baseline for CorePanel.',
    ],
    'validation' => [
        'assigned_domains' => 'The following domains are already assigned: :domains.',
        'invalid_tenant_id' => 'The tenant id could not be derived from the provided input.',
        'reserved_domains' => 'The following domains are reserved for the central application: :domains.',
    ],
];
