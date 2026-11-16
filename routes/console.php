<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\ServerEnrollmentCode;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => ServerEnrollmentCode::query()->where('expires_at', '<=', now())->delete())
    ->name('purge-expired-server-enrollment-codes')
    ->everyMinute();

Schedule::call(fn () => DB::table('operational_sync_enrollment_codes')->where('expires_at', '<=', now())->delete())
    ->name('purge-expired-operational-sync-enrollment-codes')
    ->everyMinute()
    ->when(fn (): bool => (bool) config('operational_sync.enrollment_enabled'));
