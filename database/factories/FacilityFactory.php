<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facility>
 */
class FacilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->company().' Facility',
            'status' => 'inactive',
            'locale' => 'en',
            'time_zone' => 'UTC',
        ];
    }
}
