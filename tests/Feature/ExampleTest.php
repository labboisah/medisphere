<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_serves_the_portal(): void
    {
        config(['platform.synthetic_management_enabled' => false]);
        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertSee('id="root"', false)
            ->assertSee('data-public-environment="non-production"', false)
            ->assertSee('data-portal-access="disabled"', false)
            ->assertSee('MediSphere | Connected Healthcare for Sokoto and Beyond')
            ->assertSee('<meta name="robots" content="noindex, nofollow" />', false)
            ->assertSee('Loading MediSphere...')
            ->assertDontSee('MediFlow Platform');
    }

    public function test_production_portal_does_not_advertise_synthetic_sign_in(): void
    {
        config(['platform.synthetic_management_enabled' => true]);
        $this->app['env'] = 'production';
        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertSee('data-public-environment="production"', false)
            ->assertSee('data-portal-access="disabled"', false)
            ->assertSee('<meta name="robots" content="index, follow" />', false);
    }

    public function test_unknown_api_routes_are_not_served_the_portal(): void
    {
        $this->getJson('/api/v1/not-a-route')->assertNotFound();
    }
}
