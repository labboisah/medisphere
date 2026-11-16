<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class KernelBridgeCredentialConfigTest extends TestCase
{
    public function test_credential_configuration_supports_both_names_and_prefers_nonblank_legacy_value(): void
    {
        $keys = ['KERNELBRIDGE_PRODUCT_CREDENTIAL', 'KERNELBRIDGE_API_TOKEN'];
        $originalServer = $_SERVER;
        $originalEnv = $_ENV;

        try {
            foreach ([
                ['null', 'sdk-token', 'sdk-token'],
                ['', 'sdk-token', 'sdk-token'],
                ['   ', 'sdk-token', 'sdk-token'],
                ['legacy-token', 'sdk-token', 'legacy-token'],
                ['legacy-token', 'null', 'legacy-token'],
                ['null', 'null', null],
            ] as [$legacy, $sdk, $expected]) {
                $_SERVER[$keys[0]] = $_ENV[$keys[0]] = $legacy;
                $_SERVER[$keys[1]] = $_ENV[$keys[1]] = $sdk;

                $config = require dirname(__DIR__, 2).'/config/kernelbridge.php';

                $this->assertSame($expected, $config['product_credential']);
            }
        } finally {
            $_SERVER = $originalServer;
            $_ENV = $originalEnv;
        }
    }
}
