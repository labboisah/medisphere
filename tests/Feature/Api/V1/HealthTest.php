<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_it_reports_the_versioned_api_health_status(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'service' => 'mediflow-platform-api',
                    'status' => 'available',
                    'version' => 'v1',
                ],
            ]);
    }
}
