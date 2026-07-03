<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PersonalityTestResolver;
use Illuminate\Http\Request;

/**
 * @group Personality Tests
 */
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

    /**
     * Get active personality test template.
     *
     * Requires KVKK consent. Returns active questions, answer scale, and the saved result if the user completed the test before.
     *
     * @group Personality Tests
     * @authenticated
     *
     * @response 200 {"template_id":1,"template_name":"Varsayilan Analiz","questions":[{"id":"q1","text":"Takim calismasini severim","category":"leadership"}],"scale":[1,2,3,4,5],"saved_result":null}
     * @response 401 {"message":"Unauthenticated."}
     */
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

    /**
     * Submit personality test answers.
     *
     * Requires KVKK consent. Every active question id must be present in `answers` and each value must be between 1 and 5. The result is stored under the user profile.
     *
     * @group Personality Tests
     * @authenticated
     *
     * @bodyParam answers object required Answers keyed by question id. Example: {"q1":5,"q2":3}
     * @response 200 {"message":"Kisilik analizi basariyla kaydedildi.","result":{"template_id":1,"answers":{"q1":5},"scores":{"leadership":4.5},"top_category":"leadership","summary":"Genel profil sonucun hazirlandi.","completed_at":"2026-06-30T12:00:00+03:00"}}
     * @response 422 {"message":"Eksik cevap bulundu: q1"}
     * @response 422 {"message":"Gecersiz cevap degeri: q1"}
     */
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
