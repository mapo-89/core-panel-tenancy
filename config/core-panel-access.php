<?php

declare(strict_types=1);

return [
    'resources' => [
        'tenants' => ['create', 'view', 'update', 'delete'],
    ],

    'route_permissions' => [
        'core-panel.tenants.dtApi' => 'tenants.view',
        'core-panel.tenants.index' => 'tenants.view',
        'core-panel.tenants.data' => 'tenants.view',
    ],

    'permission_groups' => [
        'system' => ['tenants'],
    ],

    'role_permissions' => [
        'admin' => [
            'tenants.create', 'tenants.view', 'tenants.update', 'tenants.delete',
        ],
    ],

    'role_groups' => [
        'admin' => 'system',
    ],

];
