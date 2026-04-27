<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationForm;
use App\Models\Period;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    /**
     * Kullanicinin kendi basvurularini listelemesi
     */
    public function myApplications(Request $request)
    {
        $applications = Application::where('user_id', $request->user()->id)
            ->with(['project', 'period'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'applications' => $applications,
        ]);
    }

    private function validateDynamicFields(?ApplicationForm $form, array $formData): array
    {
        if (! $form) {
            return $formData;
        }

        $errors = [];
        $normalized = [];

        foreach (($form->fields ?? []) as $field) {
            $fieldId = $field['id'] ?? $field['key'] ?? null;
            if (! $fieldId) {
                continue;
            }

            $label = $field['label'] ?? $fieldId;
            $type = $field['type'] ?? 'text';
            $required = (bool) ($field['required'] ?? false);
            $value = $formData[$fieldId] ?? null;

            if ($required) {
                $isEmpty =
                    $value === null ||
                    $value === '' ||
                    (is_array($value) && count(array_filter($value, fn ($item) => $item !== null && $item !== '')) === 0);

                if ($isEmpty) {
                    $errors[$fieldId] = [$label . ' alani zorunludur.'];
                    continue;
                }
            }

            if ($value === null || $value === '') {
                $normalized[$fieldId] = $type === 'checkbox' ? [] : $value;
                continue;
            }

            if (in_array($type, ['checkbox'], true)) {
                if (! is_array($value)) {
                    $errors[$fieldId] = [$label . ' icin birden fazla secim dizisi bekleniyor.'];
                    continue;
                }

                $normalized[$fieldId] = array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
                continue;
            }

            if (in_array($type, ['select', 'radio'], true) && isset($field['options']) && is_array($field['options'])) {
                if (! in_array($value, $field['options'], true)) {
                    $errors[$fieldId] = [$label . ' icin gecerli bir secim yapin.'];
                    continue;
                }
            }

            $normalized[$fieldId] = is_string($value) ? trim($value) : $value;
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * Bir projeye yeni basvuru yapma
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'form_data' => 'required|array',
        ]);

        $project = Project::findOrFail($validated['project_id']);

        if (! $project->application_open) {
            throw ValidationException::withMessages([
                'project_id' => ['Bu proje icin basvurular su an kapali.'],
            ]);
        }

        $user = $request->user();

        $currentPeriod = Period::where('project_id', $project->id)
            ->where('status', 'active')
            ->first();

        if (! $currentPeriod) {
            return response()->json(['message' => 'Bu proje icin aktif bir donem bulunamadi.'], 400);
        }

        $existingApp = Application::where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->where('period_id', $currentPeriod->id)
            ->first();

        if ($existingApp) {
            return response()->json(['message' => 'Bu projeye ve doneme ait zaten bir basvurunuz bulunuyor.'], 400);
        }

        $form = ApplicationForm::where('project_id', $project->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        $normalizedFormData = $this->validateDynamicFields($form, Arr::wrap($validated['form_data']));

        $application = Application::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $currentPeriod->id,
            'application_form_id' => $form?->id,
            'form_data' => $normalizedFormData,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Basvurunuz basariyla alindi.',
            'application' => $application,
        ], 201);
    }

    /**
     * Basvuru detayi
     */
    public function show($id, Request $request)
    {
        $application = Application::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->with(['project', 'period'])
            ->firstOrFail();

        return response()->json([
            'application' => $application,
        ]);
    }
}
