<?php

declare(strict_types=1);

return array (
  'manifest' => 
  array (
    'name' => 'Muster',
    'slug' => 'muster',
    'description' => 'Self-hosted heartbeat and cron monitoring for scheduled tasks and background services.',
    'icon' => 'https://scalpels.app/icons/muster.svg',
    'product_url' => 'https://scalpels.app/products/muster',
  ),
  'credentials' => 
  array (
    'guard' => 'bfc',
    'declaration' => NULL,
    'session_guard' => NULL,
    'app_purposes' => 
    array (
      'muster.check-in' => 'consumption',
    ),
  ),
  'ui' => 
  array (
    'landing_page' => true,
    'member_management' => true,
    'personal_credentials' => true,
    'installation_credentials' => true,
    'session_management' => true,
    'managed_transitions' => true,
    'credential_purposes' => 
    array (
      0 => 'muster.check-in',
    ),
  ),
);
