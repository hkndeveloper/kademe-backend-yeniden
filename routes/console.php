<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- KADEME OTOMASYON GÖREVLERİ --- //
use Illuminate\Support\Facades\Schedule;
use App\Jobs\RotateQrTokenJob;

// QR Kod Rotasyonu: Ekran görüntüsü hilesine karşı 30-60 saniyede bir tetiklenir (Cron en sık dakikada bir çalışır, içeriğinde detaylı loop kurulabilir veya supervisor ile daemon olarak yönetilebilir)
Schedule::job(new RotateQrTokenJob)->everyMinute();
