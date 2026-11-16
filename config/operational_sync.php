<?php

return [
    // Do not enable until enrollment, machine authentication and connector scheduling are provisioned.
    'monitoring_enabled' => (bool) env('OPERATIONAL_SYNC_MONITORING_ENABLED', false),
    'enrollment_enabled' => (bool) env('OPERATIONAL_SYNC_ENROLLMENT_ENABLED', false),
    'ingestion_enabled' => (bool) env('OPERATIONAL_SYNC_INGESTION_ENABLED', false),
    'production_machine_api_enabled' => filter_var(
        env('OPERATIONAL_SYNC_PRODUCTION_MACHINE_API_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) === true,
    'allow_insecure_ip_http' => (bool) env('OPERATIONAL_SYNC_ALLOW_INSECURE_IP_HTTP', false),
    'kernelbridge_public_keys' => json_decode((string) env('OPERATIONAL_SYNC_KERNELBRIDGE_PUBLIC_KEYS', '{}'), true),
];
