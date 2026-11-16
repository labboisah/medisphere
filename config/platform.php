<?php

return [
    'synthetic_management_enabled' => filter_var(env('SYNTHETIC_MANAGEMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true,
    'synthetic_portal_url' => env('SYNTHETIC_PORTAL_URL'),
];
