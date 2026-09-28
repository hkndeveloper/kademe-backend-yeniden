<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ApplicationScreeningService
{
    private const NUMERIC_OPERATORS = ['gt', 'lt', 'gte', 'lte'];

    /** Validate rules against the exact questions being saved or previewed. */
    public function validateRules(array $fields, array $rules): void
    {
        $questions = collect($fields)->keyBy('id');
        $errors = [];

        foreach ($fields as $index => $field) {
            if (in_array($field['type'] ?? null, ['select', 'radio', 'checkbox'], true)) {
                $options = array_values(array_filter($field['options'] ?? [], fn ($option) => is_string($option) && trim($option) !== ''));
                if ($options === [] || count($options) !== count(array_unique($options))) {
                    $errors["fields.$index.options"] = ['Seçim sorusunda boş veya tekrarlanan seçenek bulunamaz.'];
                }
            }
        }

        foreach ($rules as $index => $rule) {
            $question = $questions->get($rule['field_id'] ?? '');
            $key = "auto_reject_rules.$index";
            if (! $question || ($question['type'] ?? null) === 'file') {
                $errors["$key.field_id"] = ['Kural için dosya dışındaki mevcut bir soru seçin.'];

                continue;
            }

            $type = $question['type'];
            $operator = $rule['operator'] ?? 'equals';
            if (! in_array($rule['mode'] ?? 'reject', ['reject', 'review'], true)) {
                $errors["$key.mode"] = ['Kuralın sonucunu otomatik ret veya inceleme olarak seçin.'];
            }
            $value = trim((string) ($rule['value'] ?? ''));
            if ($value === '') {
                $errors["$key.value"] = ['Karşılaştırılacak cevap boş bırakılamaz.'];
            }
            if (in_array($operator, self::NUMERIC_OPERATORS, true)) {
                if (! in_array($type, ['text', 'longtext'], true) || ! is_numeric($value)) {
                    $errors["$key.operator"] = ['Sayısal karşılaştırma yalnız metin sorusunda sayısal bir eşikle kullanılabilir.'];
                }
            } elseif (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                if ($type === 'checkbox' && $operator !== 'contains') {
                    $errors["$key.operator"] = ['Çoklu seçim için içeriyorsa koşulunu kullanın.'];
                }
                if ($operator === 'contains' && $type !== 'checkbox') {
                    $errors["$key.operator"] = ['Tekli seçim için eşittir veya eşit değildir koşulunu kullanın.'];
                }
                if (! in_array($value, $question['options'] ?? [], true)) {
                    $errors["$key.value"] = ['Kural değeri sorunun tanımlı seçeneklerinden biri olmalıdır.'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function firstMatch(array $rules, array $answers, ?string $email = null, ?string $phone = null): ?array
    {
        $reviewMatch = null;
        foreach ($rules as $index => $rule) {
            $field = $rule['field'] ?? $rule['field_id'] ?? null;
            if (! is_string($field) || $field === '') {
                continue;
            }
            $actual = match ($field) {
                'email' => $email,
                'phone' => $phone,
                default => $answers[$field] ?? null,
            };
            $expected = $rule['value'] ?? null;
            $operator = $rule['operator'] ?? 'equals';
            if ($operator === 'empty' && ($actual === null || $actual === '' || $actual === [])) {
                $match = $this->matchResult($rule, $index);
                if ($match['mode'] === 'reject') {
                    return $match;
                }
                $reviewMatch ??= $match;

                continue;
            }
            if ($actual === null || $actual === '' || $actual === []) {
                // An unanswered optional question must never trigger a rejection by inequality.
                continue;
            }
            $actualText = is_array($actual) ? implode(' ', array_map('strval', $actual)) : (string) $actual;
            $expectedText = is_array($expected) ? implode(' ', array_map('strval', $expected)) : (string) $expected;
            $actualNumber = is_numeric($actualText) ? (float) $actualText : null;
            $expectedNumber = is_numeric($expectedText) ? (float) $expectedText : null;

            $matched = match ($operator) {
                'not_equals' => $actualText !== $expectedText,
                'contains' => is_array($actual)
                    ? in_array($expected, $actual, true)
                    : str_contains(mb_strtolower($actualText), mb_strtolower($expectedText)),
                'gt' => $actualNumber !== null && $expectedNumber !== null && $actualNumber > $expectedNumber,
                'lt' => $actualNumber !== null && $expectedNumber !== null && $actualNumber < $expectedNumber,
                'gte' => $actualNumber !== null && $expectedNumber !== null && $actualNumber >= $expectedNumber,
                'lte' => $actualNumber !== null && $expectedNumber !== null && $actualNumber <= $expectedNumber,
                'in' => is_array($expected) && in_array($actual, $expected, true),
                'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
                'empty' => false,
                'not_empty' => true,
                default => $actualText === $expectedText,
            };
            if ($matched) {
                $match = $this->matchResult($rule, $index);
                if ($match['mode'] === 'reject') {
                    return $match;
                }
                $reviewMatch ??= $match;
            }
        }

        return $reviewMatch;
    }

    private function matchResult(array $rule, int $index): array
    {
        return [
            'rule_index' => $index,
            'mode' => ($rule['mode'] ?? 'reject') === 'review' ? 'review' : 'reject',
            'reason' => $this->reason($rule),
        ];
    }

    private function reason(array $rule): string
    {
        return trim((string) ($rule['message'] ?? $rule['reason'] ?? ''))
            ?: (($rule['mode'] ?? 'reject') === 'review'
                ? 'Başvuru koordinatör incelemesi gerektiriyor.'
                : 'Basvurunuz kriter uyumsuzlugu nedeniyle reddedilmistir.');
    }
}
