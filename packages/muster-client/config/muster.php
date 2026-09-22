<?php

declare(strict_types=1);

return [
    'url' => env('MUSTER_URL'),
    'token' => env('MUSTER_TOKEN'),
    'connect_timeout' => (float) env('MUSTER_CONNECT_TIMEOUT', 0.5),
    'timeout' => (float) env('MUSTER_TIMEOUT', 1.0),
];
