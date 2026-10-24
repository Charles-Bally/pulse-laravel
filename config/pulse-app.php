<?php

return [
    'key' => env('PULSE_KEY'),
    'endpoint' => env('PULSE_ENDPOINT'),

    'send' => env('PULSE_SEND'),
    'include' => env('PULSE_INCLUDE'),
    'exclude' => env('PULSE_EXCLUDE'),
    'logs' => env('PULSE_LOGS'),
    'log_level' => env('PULSE_LOG_LEVEL'),

    'public_key' => env('PULSE_PUBLIC_KEY'),
    'clear' => env('PULSE_CLEAR'),
    'relay_fingerprint' => env('PULSE_RELAY_FINGERPRINT'),

    'app_version' => env('PULSE_APP_VERSION'),
    'disabled' => env('PULSE_DISABLED'),

    'auto_detect' => true,

    'http_probes' => [],

    'directory' => null,
];
