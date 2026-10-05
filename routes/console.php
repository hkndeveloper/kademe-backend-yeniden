<?php

use App\Jobs\RotateQrTokenJob;
use App\Models\ApplicationEmailVerification;
use App\Models\SystemSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- KADEME OTOMASYON GÖREVLERİ --- //
// QR Kod Rotasyonu: Ekran görüntüsü hilesine karşı 30-60 saniyede bir tetiklenir (Cron en sık dakikada bir çalışır, içeriğinde detaylı loop kurulabilir veya supervisor ile daemon olarak yönetilebilir)
Schedule::job(new RotateQrTokenJob)->everyMinute();

// The timestamp proves schedule:run reached the database; it does not claim that other jobs succeeded.
Schedule::call(fn () => SystemSetting::query()->updateOrCreate(
    ['key' => 'operations_scheduler_last_tick_at'],
    ['value' => now()->toIso8601String(), 'group' => 'operations'],
))->everyMinute();

// Dönemlik Kredi Reset: Her gün gece yarısı, bugün başlayan dönemlerin katılımcı kredilerini resetler.
Schedule::command('kademe:reset-period-credits')->dailyAt('00:05');

// Keep short-lived application verification records out of long-term storage.
Schedule::call(fn () => ApplicationEmailVerification::query()
    ->where('expires_at', '<', now()->subDay())
    ->delete())->dailyAt('03:45');

if (config('period_lifecycle.monitoring.archive_verify_schedule_enabled', true)) {
    Schedule::command('periods:verify-archives')
        ->dailyAt(config('period_lifecycle.monitoring.archive_verify_time', '03:15'))
        ->timezone(config('period_lifecycle.monitoring.archive_verify_timezone', 'Europe/Istanbul'))
        ->withoutOverlapping((int) config('period_lifecycle.monitoring.archive_verify_lock_minutes', 120))
        ->onOneServer();
}

if (config('application_waitlist.auto_schedule_enabled', false)
    && config('application_waitlist.auto_project_ids', []) !== []) {
    Schedule::command('applications:advance-waitlists --send')
        ->everyFifteenMinutes()
        ->withoutOverlapping(30)
        ->onOneServer();
}
