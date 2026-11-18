<?php

use App\Models\PasswordResetOtp;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    PasswordResetOtp::query()->where('created_at', '<', now()->subDay())->delete();
})->daily()->name('password-reset-otps:cleanup');

Schedule::command('reminders:send')->everyFiveMinutes()->withoutOverlapping();
