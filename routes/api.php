<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OperationalSyncMachineController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::middleware(['operational-sync.machine-api', 'throttle:30,1'])->group(function (): void {
        Route::post('/operational-sync/enroll', [OperationalSyncMachineController::class, 'enroll']);
        Route::post('/operational-sync/events', [OperationalSyncMachineController::class, 'ingest']);
    });
});
