<?php

use App\Http\Middleware\AuditSyntheticManagement;
use App\Http\Middleware\EnsureActiveOrganizationMembership;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Middleware\EnsureOperationalSyncMachineApiEnabled;
use App\Http\Middleware\EnsureOrganizationLicensed;
use App\Http\Middleware\EnsureOrganizationOnboarded;
use App\Http\Middleware\EnsureSyntheticManagementEnabled;
use App\Http\Middleware\ProtectMfaSettings;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ProtectMfaSettings::class]);
        $middleware->alias([
            'organization.membership' => EnsureActiveOrganizationMembership::class,
            'mfa.verified' => EnsureMfaVerified::class,
            'organization.onboarded' => EnsureOrganizationOnboarded::class,
            'organization.licensed' => EnsureOrganizationLicensed::class,
            'synthetic.management' => EnsureSyntheticManagementEnabled::class,
            'operational-sync.machine-api' => EnsureOperationalSyncMachineApiEnabled::class,
            'synthetic.management.audit' => AuditSyntheticManagement::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
