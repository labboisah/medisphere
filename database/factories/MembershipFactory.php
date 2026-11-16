<?php

namespace Database\Factories;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = Organization::factory();

        return [
            'user_id' => User::factory(),
            'organization_id' => $organization,
            'facility_id' => null,
            'role' => 'facility_admin',
            'status' => 'invited',
        ];
    }
}
