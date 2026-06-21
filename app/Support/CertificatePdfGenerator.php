<?php

namespace App\Support;

use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

class CertificatePdfGenerator
{
    public static function generate(Certificate $certificate): Certificate
    {
        $certificate->loadMissing(['user:id,name,surname', 'project:id,name', 'period:id,name']);

        $verificationUrl = self::verificationUrl($certificate);
        $qrSvg = null;

        try {
            $qrSvg = QrCode::format('svg')
                ->size(150)
                ->margin(1)
                ->generate($verificationUrl);
        } catch (Throwable) {
            $qrSvg = null;
        }

        $pdf = Pdf::loadHTML(self::html(
            $certificate,
            trim(($certificate->user?->name ?? '').' '.($certificate->user?->surname ?? '')),
            $certificate->project?->name ?? 'KADEME',
            $certificate->period?->name,
            $verificationUrl,
            $qrSvg,
        ))->setPaper('a4', 'landscape');

        $path = 'certificates/generated/'.strtolower((string) $certificate->verification_code).'.pdf';
        MediaStorage::disk()->put($path, $pdf->output());

        $certificate->update(['certificate_path' => $path]);

        return $certificate->fresh(['user:id,name,surname', 'project:id,name', 'period:id,name']);
    }

    public static function verificationUrl(Certificate $certificate): string
    {
        $frontendUrl = rtrim((string) config('services.frontend.url', config('app.url')), '/');

        return $frontendUrl.'/certificates/verify?code='.urlencode((string) $certificate->verification_code);
    }

    private static function html(
        Certificate $certificate,
        string $recipientName,
        string $projectName,
        ?string $periodName,
        string $verificationUrl,
        ?string $qrSvg,
    ): string {
        $title = $certificate->type === 'graduation' ? 'Mezuniyet Sertifikasi' : 'Katilim Belgesi';
        $completionLabel = $certificate->type === 'graduation' ? 'mezuniyet' : 'tamamlama';
        $safeRecipient = self::escape($recipientName ?: 'KADEME Katilimcisi');
        $safeProject = self::escape($projectName);
        $safePeriod = $periodName ? ' Donem: '.self::escape($periodName).'.' : '';
        $safeCode = self::escape((string) $certificate->verification_code);
        $safeUrl = self::escape($verificationUrl);
        $issuedAt = self::escape(($certificate->issued_at ?? now())->format('d.m.Y'));
        $qrBlock = $qrSvg
            ? '<div class="qr">'.$qrSvg.'</div>'
            : '<div class="code">'.$safeCode.'</div>';

        return <<<HTML
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111827; background: #ffffff; }
        .shell { border: 6px solid #ff6b00; padding: 36px; height: 480px; position: relative; }
        .inner { border: 1px solid #d1d5db; height: 100%; padding: 32px; text-align: center; }
        .eyebrow { color: #ff6b00; font-size: 13px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; }
        h1 { margin: 24px 0 12px; font-size: 40px; letter-spacing: 1px; }
        .recipient { margin: 24px auto 10px; font-size: 34px; font-weight: 800; border-bottom: 2px solid #111827; display: inline-block; padding: 0 36px 10px; }
        .text { margin: 18px auto; max-width: 760px; font-size: 16px; line-height: 1.7; color: #374151; }
        .meta { position: absolute; left: 70px; right: 70px; bottom: 54px; display: table; width: calc(100% - 140px); }
        .meta-col { display: table-cell; width: 33%; vertical-align: bottom; font-size: 11px; color: #4b5563; }
        .meta-col.center { text-align: center; }
        .meta-col.right { text-align: right; }
        .code { font-size: 13px; font-weight: 800; color: #111827; }
        .qr { display: inline-block; width: 150px; height: 150px; }
        .verify { margin-top: 5px; font-size: 9px; color: #6b7280; word-break: break-all; }
    </style>
</head>
<body>
    <div class="shell">
        <div class="inner">
            <div class="eyebrow">KADEME Onayli Belge</div>
            <h1>{$title}</h1>
            <div class="recipient">{$safeRecipient}</div>
            <div class="text">
                {$safeProject} kapsamindaki program surecini {$completionLabel}
                statusu ile tamamladigini belgelemek uzere duzenlenmistir.{$safePeriod}
            </div>
        </div>
        <div class="meta">
            <div class="meta-col">
                <div>Duzenlenme Tarihi</div>
                <div class="code">{$issuedAt}</div>
            </div>
            <div class="meta-col center">
                {$qrBlock}
                <div class="verify">{$safeUrl}</div>
            </div>
            <div class="meta-col right">
                <div>Dogrulama Kodu</div>
                <div class="code">{$safeCode}</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
