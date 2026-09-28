<?php

namespace App\Services;

use App\Models\ApplicationForm;

class ApplicationConsentService
{
    public const GENERAL_TEXT = 'Başvurunun değerlendirme ve kontenjan sonucuna bağlı olduğunu; mazeretsiz katılmamanın başvuru kısıtına yol açabileceğini biliyorum. Başvuru koşullarını, uyarıları ve yaptırımları okudum, kabul ediyorum.';

    private const LEGACY_DEFAULT_TEXT = 'Basvuru kosullarini, uyarilari ve yaptirimlari okudum; verdigim bilgilerin dogru oldugunu kabul ediyorum.';

    public function textFor(?ApplicationForm $form): string
    {
        return $this->textWithAdditional($form?->consent_text);
    }

    public function textWithAdditional(?string $text): string
    {
        $additionalText = trim((string) $text);

        if ($additionalText === '' || in_array($additionalText, [self::GENERAL_TEXT, self::LEGACY_DEFAULT_TEXT], true)) {
            return self::GENERAL_TEXT;
        }

        return self::GENERAL_TEXT."\n\n".$additionalText;
    }
}
