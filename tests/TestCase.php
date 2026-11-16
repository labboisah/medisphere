<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsMfa(User $user): static
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('synthetic-test-secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)->withSession([
            'mfa_verified' => [
                'user_id' => $user->getKey(),
                'secret_hash' => hash('sha256', $user->two_factor_secret),
            ],
        ]);

        return $this;
    }
}
