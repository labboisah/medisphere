<?php

use App\Http\Controllers\Api\V1\CurrentSessionController;
use App\Http\Controllers\Api\V1\FacilityController;
use App\Http\Controllers\Api\V1\OrganizationAccess;
use App\Http\Controllers\Api\V1\OrganizationAuthorizationController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\OrganizationLicensingController;
use App\Http\Controllers\Api\V1\OperationalSyncManagementController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\OrganizationOnboardingController;
use App\Http\Controllers\Api\V1\OrganizationRoleController;
use App\Http\Controllers\Api\V1\RequiredLicensesController;
use App\Http\Controllers\Api\V1\SyntheticInvitationController;
use App\Http\Controllers\Api\V1\SyntheticExchangeController;
use App\Http\Controllers\Api\V1\SyntheticPlatformController;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('portal');
});

Route::get('/organization-license/{organization}', function (Organization $organization, Request $request) {
    OrganizationAccess::requirePermission($request->user(), $organization, 'license.manage');

    return response()->view('organization-license', ['organization' => $organization])
        ->header('Cache-Control', 'no-store, private');
})->middleware(['auth', 'synthetic.management', 'mfa.verified', 'organization.onboarded']);

Route::prefix('api/v1')->group(function (): void {
    Route::post('/invitations/accept', [SyntheticInvitationController::class, 'accept'])
        ->middleware(['synthetic.management', 'guest', 'throttle:10,1']);
    Route::get('/csrf-token', function (Request $request): JsonResponse {
        return response()->json([
            'data' => [
                'token' => $request->session()->token(),
            ],
        ]);
    });

    Route::middleware('auth')->group(function (): void {
        Route::get('/session', CurrentSessionController::class);
        Route::middleware(['synthetic.management', 'mfa.verified', 'synthetic.management.audit'])->where([
            'organization' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'facility' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'invitation' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'membership' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'installation' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'code' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'role' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'link' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
            'grant' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        ])->group(function (): void {
            Route::get('/onboarding', [OrganizationOnboardingController::class, 'index'])->name('management.onboarding.index');
            Route::post('/organizations/{organization}/onboarding', [OrganizationOnboardingController::class, 'store'])->middleware('throttle:10,1')->name('management.onboarding.complete');
            Route::get('/platform/organizations', [SyntheticPlatformController::class, 'index'])->name('management.platform.organizations.index');
            Route::post('/platform/organizations', [SyntheticPlatformController::class, 'store'])->middleware('throttle:10,1')->name('management.platform.organizations.create');
            Route::get('/platform/roles', [OrganizationRoleController::class, 'index'])->name('management.platform.roles.index');
            Route::post('/platform/roles', [OrganizationRoleController::class, 'store'])->name('management.platform.roles.create');
            Route::patch('/platform/roles/{role}', [OrganizationRoleController::class, 'update'])->name('management.platform.roles.update');
            Route::middleware('organization.onboarded')->group(function (): void {
                Route::get('/licensing/required', RequiredLicensesController::class)->name('management.licensing.required');
                Route::post('/organizations/{organization}/licensing/link', [OrganizationLicensingController::class, 'link'])->middleware('throttle:10,1')->name('management.licensing.link');
                Route::post('/organizations/{organization}/licensing/refresh', [OrganizationLicensingController::class, 'refresh'])->middleware('throttle:10,1')->name('management.licensing.refresh');
                Route::delete('/organizations/{organization}/licensing/link', [OrganizationLicensingController::class, 'unlink'])->name('management.licensing.unlink');
                Route::middleware('organization.licensed')->group(function (): void {
                    Route::get('/organizations/{organization}/licensing', [OrganizationLicensingController::class, 'show'])->name('management.licensing.show');
                    Route::get('/organizations', [OrganizationController::class, 'index'])->name('management.organizations.index');
                    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('management.organizations.show');
                    Route::get('/organizations/{organization}/roles', [OrganizationRoleController::class, 'available'])->name('management.roles.available');
                    Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->name('management.organizations.update');
                    Route::post('/organizations/{organization}/invitations', [SyntheticInvitationController::class, 'store'])->middleware('throttle:10,1')->name('management.invitations.create');
                    Route::get('/organizations/{organization}/invitations', [OrganizationInvitationController::class, 'index'])->name('management.invitations.index');
                    Route::post('/organizations/{organization}/invitations/{invitation}/resend', [OrganizationInvitationController::class, 'resend'])->middleware('throttle:10,1')->name('management.invitations.resend');
                    Route::delete('/organizations/{organization}/invitations/{invitation}', [OrganizationInvitationController::class, 'revoke'])->name('management.invitations.revoke');
                    Route::get('/organizations/{organization}/memberships', [OrganizationMembershipController::class, 'index'])->name('management.memberships.index');
                    Route::patch('/organizations/{organization}/memberships/{membership}', [OrganizationMembershipController::class, 'update'])->name('management.memberships.update');
                    Route::delete('/organizations/{organization}/memberships/{membership}', [OrganizationMembershipController::class, 'destroy'])->name('management.memberships.delete');
                    Route::post('/organizations/{organization}/installations', [OrganizationLicensingController::class, 'registerInstallation'])->name('management.installations.register');
                    Route::post('/organizations/{organization}/server-enrollment-codes', [OrganizationLicensingController::class, 'issueEnrollmentCode'])->middleware('throttle:10,1')->name('management.installations.enrollment-code');
                    Route::get('/organizations/{organization}/server-enrollment-codes', [OrganizationLicensingController::class, 'enrollmentCodes'])->name('management.installations.enrollment-codes');
                    Route::get('/organizations/{organization}/activated-servers', [OrganizationLicensingController::class, 'activatedServers'])->name('management.installations.activated-servers');
                    Route::get('/organizations/{organization}/operational-sync-summary', [OrganizationLicensingController::class, 'syncSummary'])->name('management.operational-sync.summary');
                    Route::post('/organizations/{organization}/operational-sync-enrollment', [OperationalSyncManagementController::class, 'issue'])->middleware('throttle:10,1')->name('management.operational-sync.issue');
                    Route::delete('/organizations/{organization}/operational-sync/{installation}', [OperationalSyncManagementController::class, 'revoke'])->middleware('throttle:10,1')->name('management.operational-sync.revoke');
                    Route::post('/organizations/{organization}/server-enrollment-codes/{code}/reveal', [OrganizationLicensingController::class, 'revealEnrollmentCode'])->middleware('throttle:10,1')->name('management.installations.enrollment-code-reveal');
                    Route::post('/organizations/{organization}/installations/{installation}/activation-code', [OrganizationLicensingController::class, 'issueActivationCode'])->middleware('throttle:10,1')->name('management.installations.activation-code');
                    Route::post('/organizations/{organization}/installations/{installation}/revoke', [OrganizationLicensingController::class, 'revokeInstallation'])->name('management.installations.revoke');
                    Route::get('/organizations/{organization}/facilities', [FacilityController::class, 'index'])->name('management.facilities.index');
                    Route::post('/organizations/{organization}/facilities', [FacilityController::class, 'store'])->name('management.facilities.create');
                    Route::get('/organizations/{organization}/facilities/{facility}', [FacilityController::class, 'show'])->name('management.facilities.show');
                    Route::patch('/organizations/{organization}/facilities/{facility}', [FacilityController::class, 'update'])->name('management.facilities.update');
                    Route::get('/organizations/{organization}/synthetic-exchange/links', [SyntheticExchangeController::class, 'index'])->name('management.synthetic-exchange.links.index');
                    Route::post('/organizations/{organization}/synthetic-exchange/links', [SyntheticExchangeController::class, 'storeLink'])->middleware('throttle:30,1')->name('management.synthetic-exchange.links.create');
                    Route::post('/organizations/{organization}/synthetic-exchange/links/{link}/review', [SyntheticExchangeController::class, 'reviewLink'])->middleware('throttle:30,1')->name('management.synthetic-exchange.links.review');
                    Route::post('/organizations/{organization}/synthetic-exchange/links/{link}/grants', [SyntheticExchangeController::class, 'storeGrant'])->middleware('throttle:30,1')->name('management.synthetic-exchange.grants.create');
                    Route::delete('/organizations/{organization}/synthetic-exchange/grants/{grant}', [SyntheticExchangeController::class, 'revokeGrant'])->name('management.synthetic-exchange.grants.revoke');
                });
            });
        });
        Route::get('/organizations/{organization}/authorization', OrganizationAuthorizationController::class)
            ->middleware(['mfa.verified', 'organization.onboarded', 'organization.licensed', 'organization.membership']);
    });
});
