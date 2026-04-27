<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PersonalityTestController extends Controller
{
    private function questions(): array
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

    private function scale(): array
    {
        return [
            1 => 'Kesinlikle katilmiyorum',
            2 => 'Katilmiyorum',
            3 => 'Kararsizim',
            4 => 'Katiliyorum',
            5 => 'Kesinlikle katiliyorum',
        ];
    }

    public function show(Request $request)
    {
        $profile = $request->user()->profile;

        return response()->json([
            'questions' => $this->questions(),
            'scale' => $this->scale(),
            'saved_result' => $profile?->personality_test_data,
        ]);
    }

    public function submit(Request $request)
    {
        $questions = collect($this->questions());
        $questionIds = $questions->pluck('id')->all();

        $validated = $request->validate([
            'answers' => 'required|array',
        ]);

        $answers = $validated['answers'];

        foreach ($questionIds as $questionId) {
            if (!array_key_exists($questionId, $answers)) {
                return response()->json([
                    'message' => "Eksik cevap bulundu: {$questionId}",
                ], 422);
            }

            if (!in_array((int) $answers[$questionId], [1, 2, 3, 4, 5], true)) {
                return response()->json([
                    'message' => "Gecersiz cevap degeri: {$questionId}",
                ], 422);
            }
        }

        $scores = $questions
            ->groupBy('category')
            ->map(function ($items, $category) use ($answers) {
                $sum = $items->sum(fn ($item) => (int) $answers[$item['id']]);
                return round($sum / max(count($items), 1), 2);
            })
            ->toArray();

        arsort($scores);
        $topCategory = array_key_first($scores);

        $summaries = [
            'leadership' => 'Liderlik ve inisiyatif yonun guclu gorunuyor.',
            'social' => 'Iletisim ve empati tarafin one cikiyor.',
            'execution' => 'Disiplin ve uygulama odagin dikkat cekiyor.',
            'resilience' => 'Uyum ve psikolojik dayanikliligin guclu gorunuyor.',
        ];

        $result = [
            'answers' => array_map(fn ($value) => (int) $value, $answers),
            'scores' => $scores,
            'top_category' => $topCategory,
            'summary' => $summaries[$topCategory] ?? 'Genel profil sonucun hazirlandi.',
            'completed_at' => now()->toIso8601String(),
        ];

        $request->user()->profile()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['personality_test_data' => $result]
        );

        return response()->json([
            'message' => 'Kisilik analizi basariyla kaydedildi.',
            'result' => $result,
        ]);
    }
}
