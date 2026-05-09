<?php

return [
    'brand' => [
        'name' => env('PBB_SUITE_NAME', 'PBB Emergency Response Suite'),
        'short_name' => env('PBB_SUITE_SHORT_NAME', 'PBB Response'),
        'host' => env('PBB_SUITE_HOST', 'hotline.pbb.ph'),
    ],

    'surface_modules' => [
        'public' => 'hotline',
        'citizen' => 'hotline',
        'caller' => 'hotline',
        'operator' => 'incidents',
        'command' => 'command',
        'admin' => 'resources',
    ],

    'modules' => [
        [
            'id' => 'hotline',
            'label' => 'Hotline',
            'description' => 'Citizen emergency intake, call routing, and live incident communication.',
            'status' => 'active',
            'href' => '/citizen',
            'surfaces' => ['public', 'citizen', 'caller'],
        ],
        [
            'id' => 'incidents',
            'label' => 'Incidents',
            'description' => 'Operator workbench, incident history, transfers, media, and field details.',
            'status' => 'active',
            'href' => '/operator',
            'surfaces' => ['operator'],
        ],
        [
            'id' => 'resources',
            'label' => 'Resources',
            'description' => 'Resource types, inventories, and response catalog administration.',
            'status' => 'foundation',
            'href' => '/admin?module=resources',
            'surfaces' => ['admin'],
        ],
        [
            'id' => 'teams',
            'label' => 'Teams',
            'description' => 'Team catalog, assignments, and response coordination.',
            'status' => 'foundation',
            'href' => '/admin?module=teams',
            'surfaces' => ['admin', 'operator'],
        ],
        [
            'id' => 'command',
            'label' => 'Command',
            'description' => 'Command dashboard, SITREPs, alert posture, and broadcasts.',
            'status' => 'active',
            'href' => '/command',
            'surfaces' => ['command'],
        ],
    ],
];
