<?php

use App\Jobs\RotateQrTokenJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- KADEME OTOMASYON GÖREVLERİ --- //
// QR Kod Rotasyonu: Ekran görüntüsü hilesine karşı 30-60 saniyede bir tetiklenir (Cron en sık dakikada bir çalışır, içeriğinde detaylı loop kurulabilir veya supervisor ile daemon olarak yönetilebilir)
Schedule::job(new RotateQrTokenJob)->everyMinute();

// Dönemlik Kredi Reset: Her gün gece yarısı, bugün başlayan dönemlerin katılımcı kredilerini resetler.
Schedule::command('kademe:reset-period-credits')->dailyAt('00:05');

if (config('period_lifecycle.monitoring.archive_verify_schedule_enabled', true)) {
    Schedule::command('periods:verify-archives')
        ->dailyAt(config('period_lifecycle.monitoring.archive_verify_time', '03:15'))
        ->timezone(config('period_lifecycle.monitoring.archive_verify_timezone', 'Europe/Istanbul'))
        ->withoutOverlapping((int) config('period_lifecycle.monitoring.archive_verify_lock_minutes', 120))
        ->onOneServer();
}
