<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class SyntheticPlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('platform.synthetic_management_enabled') !== true) {
            throw new RuntimeException('Synthetic management must be enabled in a local or testing environment.');
        }
        if ($this->command === null) {
            throw new RuntimeException('Run this seeder from the console to receive the generated password.');
        }
        $email = 'admin@mediflow.ng';

        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new RuntimeException('The synthetic platform administrator email already exists; no accounts were changed.');
        }
        $password = 'pass1234';
        
        $user = new User(['name' => 'Synthetic Platform Administrator', 'email' => $email, 'password' => $password]);
        $user->synthetic_platform_admin = true;
        $user->save();

        
        $this->command->info("Synthetic platform administrator email: {$email}");
        $this->command->info("One-time displayed password: {$password}");
        $this->command->warn('Synthetic data only. Enroll in MFA after signing in. The password will not be shown again.');
    }
}
