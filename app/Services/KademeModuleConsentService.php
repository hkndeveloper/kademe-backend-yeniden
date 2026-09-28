<?php

namespace App\Services;

use App\Models\ProjectModule;

class KademeModuleConsentService
{
    public const REQUIRED_LABEL = 'Sık sorulan soruları, başvuru koşullarını ve uyarıları okudum, kabul ediyorum.';

    private const GENERAL_WARNING = 'Modüle kayıt olmak, modülü tamamlamak veya belge almaya hak kazanmak anlamına gelmez. Program duyurularını ve katılım koşullarını takip edin; mazeretsiz katılmama sonraki başvurularınızı etkileyebilir.';

    public function faqItemsFor(ProjectModule $module): array
    {
        $items = collect($module->faq_items ?? [])
            ->filter(fn ($item) => is_array($item) && trim((string) ($item['question'] ?? '')) !== '' && trim((string) ($item['answer'] ?? '')) !== '')
            ->map(fn ($item) => [
                'question' => trim((string) $item['question']),
                'answer' => trim((string) $item['answer']),
            ])
            ->values()
            ->all();

        if ($items !== []) {
            return $items;
        }

        return [[
            'question' => 'Modül başvurum nasıl sonuçlanır?',
            'answer' => $module->requires_coordinator_approval
                ? 'Başvurunuz koordinatör incelemesine gönderilir; sonucunu bu ekrandan takip edebilirsiniz.'
                : 'Başvurunuz gönderildiğinde modül kaydınız otomatik onaylanır; durumunu bu ekrandan görebilirsiniz.',
        ]];
    }

    public function warningTextFor(ProjectModule $module): string
    {
        $additional = trim((string) ($module->warning_text ?? ''));

        return $additional === '' ? self::GENERAL_WARNING : self::GENERAL_WARNING."\n\n".$additional;
    }

    public function textFor(ProjectModule $module): string
    {
        $faq = collect($this->faqItemsFor($module))
            ->map(fn ($item) => $item['question']."\n".$item['answer'])
            ->implode("\n\n");
        $customLabel = trim((string) ($module->consent_checkbox_label ?? ''));
        $label = in_array($customLabel, ['', 'Okudum', 'Okudum, kabul ediyorum.'], true)
            ? self::REQUIRED_LABEL
            : self::REQUIRED_LABEL."\n".$customLabel;

        return "KADEME+ modülü: {$module->title}\n\nSık sorulan sorular\n{$faq}\n\nUyarılar ve yaptırımlar\n"
            .$this->warningTextFor($module)."\n\nOnay\n{$label}";
    }

    public function hashFor(ProjectModule $module): string
    {
        return hash('sha256', $this->textFor($module));
    }
}
