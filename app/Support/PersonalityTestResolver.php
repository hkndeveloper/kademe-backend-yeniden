<?php

namespace App\Support;

use App\Models\PersonalityTestTemplate;
use Illuminate\Support\Facades\Schema;

class PersonalityTestResolver
{
    public static function defaultQuestions(): array
    {
        return [
            ['id' => 'vision', 'category' => 'leadership', 'text' => 'Uzun vadeli hedefler belirlemek beni motive eder.'],
            ['id' => 'initiative', 'category' => 'leadership', 'text' => 'Bir grupta sorumluluk almakta rahat hissederim.'],
            ['id' => 'empathy', 'category' => 'social', 'text' => 'Baskalarinin duygularini hizlica fark ederim.'],
            ['id' => 'communication', 'category' => 'social', 'text' => 'Yeni insanlarla iletisim kurmak benim icin kolaydir.'],
            ['id' => 'discipline', 'category' => 'execution', 'text' => 'Plan yaptigimda o plana sadik kalmaya calisirim.'],
            ['id' => 'focus', 'category' => 'execution', 'text' => 'Uzun sure dikkat gerektiren islerde verimli kalabilirim.'],
            ['id' => 'adaptability', 'category' => 'resilience', 'text' => 'Beklenmedik degisikliklere hizli uyum saglarim.'],
            ['id' => 'stress', 'category' => 'resilience', 'text' => 'Yogun baski altinda sakin kalabilirim.'],
        ];
    }

    public static function defaultSummaries(): array
    {
        return [
            'leadership' => 'Liderlik ve inisiyatif yonun guclu gorunuyor.',
            'social' => 'Iletisim ve empati tarafin one cikiyor.',
            'execution' => 'Disiplin ve uygulama odagin dikkat cekiyor.',
            'resilience' => 'Uyum ve psikolojik dayanikliligin guclu gorunuyor.',
        ];
    }

    public static function scale(): array
    {
        return [
            1 => 'Kesinlikle katilmiyorum',
            2 => 'Katilmiyorum',
            3 => 'Kararsizim',
            4 => 'Katiliyorum',
            5 => 'Kesinlikle katiliyorum',
        ];
    }

    public static function activeTemplate(): ?PersonalityTestTemplate
    {
        if (! Schema::hasTable('personality_test_templates')) {
            return null;
        }

        return PersonalityTestTemplate::query()
            ->with(['questions', 'resultRanges'])
            ->where('is_active', true)
            ->latest('updated_at')
            ->latest('id')
            ->first();
    }

    public static function resolved(): array
    {
        $template = self::activeTemplate();

        if (! $template || $template->questions->isEmpty()) {
            return [
                'template_id' => null,
                'template_name' => 'Varsayilan KADEME Kisilik Analizi',
                'questions' => self::defaultQuestions(),
                'summaries' => self::defaultSummaries(),
            ];
        }

        return [
            'template_id' => $template->id,
            'template_name' => $template->name,
            'questions' => $template->questions->map(fn ($question) => [
                'id' => $question->question_key,
                'category' => $question->category,
                'text' => $question->text,
            ])->values()->all(),
            'summaries' => $template->resultRanges
                ->mapWithKeys(fn ($range) => [$range->category => $range->summary])
                ->all() ?: self::defaultSummaries(),
        ];
    }
}
