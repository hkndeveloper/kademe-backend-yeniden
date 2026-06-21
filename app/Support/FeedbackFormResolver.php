<?php

namespace App\Support;

use App\Models\FeedbackFormTemplate;
use App\Models\Program;
use Illuminate\Support\Facades\Schema;

class FeedbackFormResolver
{
    public static function defaultQuestions(): array
    {
        return [
            [
                'id' => 'content_quality',
                'label' => 'Oturumun icerik kalitesini nasil degerlendiriyorsun?',
                'type' => 'rating',
                'min' => 1,
                'max' => 5,
                'required' => true,
            ],
            [
                'id' => 'speaker_quality',
                'label' => 'Konusmaci veya yuruten ekip faydali miydi?',
                'type' => 'rating',
                'min' => 1,
                'max' => 5,
                'required' => true,
            ],
            [
                'id' => 'organization_quality',
                'label' => 'Oturum organizasyonu ve akisindan memnun kaldin mi?',
                'type' => 'rating',
                'min' => 1,
                'max' => 5,
                'required' => true,
            ],
            [
                'id' => 'comment',
                'label' => 'Eklemek istedigin gorus veya oneriler',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public static function forProgram(?Program $program): array
    {
        if (! $program || ! Schema::hasTable('feedback_form_templates')) {
            return self::defaultQuestions();
        }

        $template = null;

        if ($program->feedback_form_template_id) {
            $template = FeedbackFormTemplate::query()
                ->with('questions')
                ->where('is_active', true)
                ->find($program->feedback_form_template_id);
        }

        if (! $template) {
            $template = FeedbackFormTemplate::query()
                ->with('questions')
                ->where('is_active', true)
                ->where(function ($query) use ($program) {
                    $query->where('project_id', $program->project_id)
                        ->orWhereNull('project_id');
                })
                ->orderByRaw('CASE WHEN project_id IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->first();
        }

        if (! $template || $template->questions->isEmpty()) {
            return self::defaultQuestions();
        }

        return $template->questions->map(function ($question) {
            $type = in_array($question->type, ['text', 'choice'], true) ? $question->type : 'rating';

            return [
                'id' => $question->question_key,
                'label' => $question->label,
                'type' => $type,
                'options' => $type === 'choice' ? array_values($question->options ?? []) : null,
                'min' => $type === 'rating' ? ($question->min_value ?? 1) : null,
                'max' => $type === 'rating' ? ($question->max_value ?? 5) : null,
                'required' => (bool) $question->is_required,
            ];
        })->values()->all();
    }

    public static function ratingQuestions(array $questions): array
    {
        return array_values(array_filter($questions, fn ($question) => ($question['type'] ?? null) === 'rating'));
    }

    public static function commentQuestions(array $questions): array
    {
        return array_values(array_filter($questions, fn ($question) => ($question['type'] ?? null) === 'text'));
    }

    public static function choiceQuestions(array $questions): array
    {
        return array_values(array_filter($questions, fn ($question) => ($question['type'] ?? null) === 'choice'));
    }
}
