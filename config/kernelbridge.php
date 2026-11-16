<?php

$productCredential = env('KERNELBRIDGE_PRODUCT_CREDENTIAL');

return [
    'api_url' => env('KERNELBRIDGE_API_URL', 'https://kernelbridge.com/api/v1'),
    'product_code' => env('KERNELBRIDGE_PRODUCT_CODE', 'MEDIFLOW'),
    'product_credential' => filled($productCredential) ? $productCredential : env('KERNELBRIDGE_API_TOKEN'),
    'timeout_seconds' => (int) env('KERNELBRIDGE_TIMEOUT_SECONDS', 10),
];
