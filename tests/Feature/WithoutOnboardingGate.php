<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicensed;
use App\Http\Middleware\EnsureOrganizationOnboarded;

trait WithoutOnboardingGate
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureOrganizationOnboarded::class);
        $this->withoutMiddleware(EnsureOrganizationLicensed::class);
    }
}
