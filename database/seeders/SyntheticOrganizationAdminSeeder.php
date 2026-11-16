<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SyntheticOrganizationAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('platform.synthetic_management_enabled') !== true) {
            throw new RuntimeException('Synthetic management must be enabled in a local or testing environment.');
        }

        if ($this->command === null) {
            throw new RuntimeException('Run this seeder from the console to receive the generated password.');
        }

        $email = 'synthetic-admin@example.test';
        $password = Str::random(32);

        DB::transaction(function () use ($email, $password): void {
            if (User::where('email', $email)->exists()) {
                throw new RuntimeException('The synthetic administrator email already exists; no accounts were changed.');
            }

            $user = User::create([
                'name' => 'Synthetic Administrator',
                'email' => $email,
                'password' => $password,
            ]);

            $organization = Organization::create([
                'name' => 'Synthetic Healthcare Organization',
                'status' => 'inactive',
            ]);

            $organization->facilities()->create([
                'name' => 'Synthetic Clinic',
                'status' => 'inactive',
                'locale' => 'en',
                'time_zone' => 'Africa/Lagos',
            ]);

            $membership = new Membership([
                'role' => 'organization_admin',
                'status' => 'active',
            ]);
            $membership->organization()->associate($organization);
            $user->memberships()->save($membership);
        });

        $this->command->info("Synthetic administrator email: {$email}");
        $this->command->info("One-time displayed password: {$password}");
        $this->command->warn('Use synthetic data only. Enroll in MFA after signing in. The password will not be shown again.');
    }
}
