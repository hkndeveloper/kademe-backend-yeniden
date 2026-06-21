<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PersonalityTestResolver;
use Illuminate\Http\Request;

class PersonalityTestController extends Controller
{
    private function questions(): array
    {
        return PersonalityTestResolver::resolved()['questions'];
    }

    private function scale(): array
    {
        return PersonalityTestResolver::scale();
    }

    public function show(Request $request)
    {
        $profile = $request->user()->profile;
        $test = PersonalityTestResolver::resolved();

        return response()->json([
            'template_id' => $test['template_id'],
            'template_name' => $test['template_name'],
            'questions' => $test['questions'],
            'scale' => $this->scale(),
            'saved_result' => $profile?->personality_test_data,
        ]);
    }

    public function submit(Request $request)
    {
        $test = PersonalityTestResolver::resolved();
        $questions = collect($test['questions']);
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

        $summaries = $test['summaries'];

        $result = [
            'template_id' => $test['template_id'],
            'template_name' => $test['template_name'],
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
