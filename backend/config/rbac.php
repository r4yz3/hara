<?php

/*
| Role and permission catalogue. Mirrors docs/security/permission-matrix.md,
| accepted on 2026-11-13; RbacCatalogueTest fails if the two drift apart in
| the ways that matter. RbacSeeder writes this into the database.
|
| scope_types   : where a grant of the role may be anchored.
| assignable_by : roles that may grant it. platform_owner may additionally
|                 grant every role except platform_owner itself, which is
|                 never grantable through the application (bootstrap only).
*/

$read = ['cities.read', 'zones.read'];

return [

    'permissions' => [
        'cities.read',
        'cities.write',
        'zones.read',
        'zones.write',
        'merchants.read',
        'merchants.approve',
        'riders.read',
        'users.read',
        'users.roles.assign',
        'audit.read',
        'system.status.read',
        'flags.write',
        'dispatch.read',
        'dispatch.override',
    ],

    'roles' => [
        'platform_owner' => [
            'label' => 'Platform owner',
            'scope_types' => ['global'],
            'assignable_by' => [],
            'permissions' => '*',
        ],
        'platform_admin' => [
            'label' => 'Platform admin',
            'scope_types' => ['global', 'city'],
            'assignable_by' => [],
            'permissions' => [
                'cities.read', 'cities.write', 'zones.read', 'zones.write', 'merchants.read', 'merchants.approve',
                'riders.read', 'users.read', 'users.roles.assign', 'audit.read', 'system.status.read',
                'dispatch.read', 'dispatch.override',
            ],
        ],
        'city_manager' => [
            'label' => 'City manager',
            'scope_types' => ['city'],
            'assignable_by' => ['platform_admin'],
            'permissions' => [
                ...$read, 'zones.write', 'merchants.read', 'riders.read', 'users.read', 'users.roles.assign',
                'dispatch.read', 'dispatch.override',
            ],
        ],
        'dispatcher' => [
            'label' => 'Dispatcher',
            'scope_types' => ['city'],
            'assignable_by' => ['platform_admin', 'city_manager'],
            'permissions' => [...$read, 'riders.read', 'dispatch.read', 'dispatch.override'],
        ],
        'finance' => [
            'label' => 'Finance',
            'scope_types' => ['global', 'city'],
            'assignable_by' => [],
            'permissions' => ['cities.read', 'merchants.read'],
        ],
        'support' => [
            'label' => 'Support',
            'scope_types' => ['global', 'city'],
            'assignable_by' => ['platform_admin'],
            'permissions' => [...$read, 'merchants.read', 'users.read'],
        ],

        // Granted by onboarding and registration flows in later work, never
        // through the role-grant service. They carry no permissions: their
        // access is by ownership (own merchant, own profile, own orders).
        'merchant_owner' => ['label' => 'Merchant owner', 'scope_types' => ['merchant'], 'assignable_by' => null, 'permissions' => []],
        'merchant_manager' => ['label' => 'Merchant manager', 'scope_types' => ['merchant', 'branch'], 'assignable_by' => null, 'permissions' => []],
        'merchant_staff' => ['label' => 'Merchant staff', 'scope_types' => ['branch'], 'assignable_by' => null, 'permissions' => []],
        'rider' => ['label' => 'Rider', 'scope_types' => ['global'], 'assignable_by' => null, 'permissions' => []],
        'customer' => ['label' => 'Customer', 'scope_types' => ['global'], 'assignable_by' => null, 'permissions' => []],
    ],

];
