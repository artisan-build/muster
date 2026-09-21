<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Muster',
        'slug' => 'muster',
        'description' => 'Self-hosted heartbeat and cron monitoring for scheduled tasks and background services.',
        'icon' => 'https://scalpels.app/icons/muster.svg',
        'product_url' => 'https://scalpels.app/products/muster',
    ],
    'credentials' => [
        'guard' => 'bfc',
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
            'muster.check-in' => 'consumption',
            'muster.history.read' => 'consumption',
        ],
    ],
    'ui' => [
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => true,
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => [
            0 => 'muster.check-in',
            1 => 'muster.history.read',
        ],
    ],
];
