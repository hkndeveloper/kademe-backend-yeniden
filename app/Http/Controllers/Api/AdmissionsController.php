<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectModule;
use App\Models\ProjectModuleEnrollment;
use App\Models\ProjectTraining;
use App\Services\ApplicationIntakeService;
use App\Services\ApplicationMessageService;
use App\Services\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdmissionsController extends Controller
{
    use ResolvesProjectPeriodContext;

    public function targets(ApplicationIntakeService $intake)
    {
        $targets = [];
        foreach (Project::where('status', 'active')->where('is_public', true)->with(['currentPeriod', 'applicationWindows'])->orderBy('name')->get() as $project) {
            $period = $project->currentPeriodOrLegacy();
            if (! $intake->isOpen($project, $period)) {
                continue;
            }
            $base = ['project_id' => $project->id, 'project_name' => $project->name, 'slug' => $project->slug, 'period_id' => $period->id, 'period_name' => $period->name];
            if ($project->application_scope === 'training') {
                foreach (ProjectTraining::where('project_id', $project->id)->where('period_id', $period->id)->orderBy('title')->get() as $training) {
                    if ($training->isOpen()) {
                        $targets[] = $base + ['training_id' => $training->id, 'title' => $training->title, 'ends_at' => $training->application_end_at, 'href' => '/projects/'.$project->slug.'?apply=1&training='.$training->id];
                    }
                }
            } else {
                $targets[] = $base + ['training_id' => null, 'title' => $project->name, 'ends_at' => $intake->windowFor($project, $period)?->ends_at ?? $project->application_end_at, 'href' => '/projects/'.$project->slug.'?apply=1'];
            }
        }

        return response()->json(['targets' => $targets])->header('Cache-Control', 'no-store');
    }

    private function project(Request $request, int $id, string $permission): Project
    {
        $project = Project::findOrFail($id);
        abort_unless(app(PermissionResolver::class)->canAccessProject($request->user(), $permission, $id), 403);

        return $project;
    }

    public function index(Request $request, int $id)
    {
        $project = $this->project($request, $id, 'applications.view');

        return response()->json([
            'application_scope' => $project->application_scope,
            'trainings' => ProjectTraining::where('project_id', $id)->with('modules:id,project_id,period_id,training_id,title')->get(),
            'templates' => DB::table('application_message_templates')->where('project_id', $id)->get(),
            'events' => ApplicationMessageService::EVENTS, 'variables' => ApplicationMessageService::VARIABLES,
            'periods' => $project->periods()->get(['id', 'name', 'status']),
            'current_period_id' => $project->currentPeriodOrLegacy()?->id,
            'modules' => ProjectModule::where('project_id', $id)->get(['id', 'title', 'period_id', 'training_id']),
            'sessions' => Program::where('project_id', $id)->get(['id', 'title', 'period_id', 'project_module_id']),
        ]);
    }

    public function settings(Request $request, int $id)
    {
        $project = $this->project($request, $id, 'applications.intake.manage');
        $data = $request->validate(['application_scope' => ['required', Rule::in(['project', 'training'])]]);
        DB::transaction(function () use ($project, $request, $data) {
            $current = Project::lockForUpdate()->findOrFail($project->id);
            $this->assertPeriodConfigurable($request, $current->currentPeriodOrLegacy()?->id);
            $current->update($data);
        });

        return response()->json(['message' => 'Başvuru kapsamı kaydedildi.']);
    }

    public function saveTraining(Request $request, int $id, ?int $trainingId = null)
    {
        $project = $this->project($request, $id, 'applications.intake.manage');
        abort_unless($project->application_scope === 'training', 422, 'Bu proje eğitim bazında başvuru almıyor.');
        $data = $request->validate([
            'period_id' => 'required|integer|exists:periods,id', 'title' => 'required|string|max:255', 'description' => 'nullable|string|max:10000',
            'is_active' => 'required|boolean', 'application_open' => 'required|boolean', 'quota' => 'nullable|integer|min:0',
            'application_start_at' => 'nullable|date', 'application_end_at' => 'nullable|date|after_or_equal:application_start_at',
            'module_ids' => 'sometimes|array', 'module_ids.*' => 'integer|distinct|exists:project_modules,id',
        ]);
        $training = DB::transaction(function () use ($request, $project, $data, $trainingId) {
            Project::lockForUpdate()->findOrFail($project->id);
            $period = Period::where('project_id', $project->id)->findOrFail($data['period_id']);
            $this->assertPeriodConfigurable($request, $period->id);
            $training = $trainingId ? ProjectTraining::where('project_id', $project->id)->lockForUpdate()->findOrFail($trainingId) : new ProjectTraining;
            if ($training->exists) {
                $this->assertPeriodConfigurable($request, $training->period_id);
                abort_if($training->period_id !== $period->id, 422, 'Eğitimin dönemi değiştirilemez; yeni dönem için yeni eğitim açın.');
            }
            $ids = $data['module_ids'] ?? null;
            unset($data['module_ids']);
            $training->fill($data + ['project_id' => $project->id])->save();
            if ($ids !== null) {
                $modules = ProjectModule::where('project_id', $project->id)->where('period_id', $period->id)->whereIn('id', $ids)->lockForUpdate()->get();
                abort_unless($modules->count() === count($ids), 422, 'Modüller aynı proje ve döneme ait olmalı.');
                foreach ($modules as $module) {
                    abort_if($module->training_id && $module->training_id !== $training->id, 422, 'Modül başka bir eğitime bağlı.');
                    abort_if(! $module->training_id && ProjectModuleEnrollment::where('project_module_id', $module->id)->exists(), 422, 'Bu modülün mevcut katılımcıları var. Erişimlerini korumak için yeni eğitimde yeni bir modül oluşturun.');
                }
                // Do not change established module access by silently moving/removing it.
                $assigned = ProjectModule::where('training_id', $training->id)->pluck('id')->all();
                abort_if(array_diff($assigned, $ids), 422, 'Bağlı modüller buradan çıkarılamaz; mevcut katılımcı erişimini koruyun.');
                ProjectModule::whereIn('id', $ids)->update(['training_id' => $training->id]);
            }

            return $training;
        });

        return response()->json(['training' => $training], $trainingId ? 200 : 201);
    }

    public function saveTemplate(Request $request, int $id, string $event)
    {
        $this->project($request, $id, 'applications.intake.manage');
        abort_unless(in_array($event, ApplicationMessageService::EVENTS, true), 404);
        $data = $request->validate(['email_subject' => 'nullable|string|max:255', 'email_body' => 'nullable|string|max:10000', 'sms_body' => 'nullable|string|max:1500']);
        app(ApplicationMessageService::class)->validateVariables($data);
        DB::table('application_message_templates')->updateOrInsert(['project_id' => $id, 'event' => $event], $data + ['updated_at' => now(), 'created_at' => now()]);

        return response()->json(['message' => 'Mesaj şablonu kaydedildi.']);
    }

    public function assignSession(Request $request, int $id, int $programId)
    {
        $this->project($request, $id, 'programs.update');
        $data = $request->validate(['project_module_id' => 'required|integer|exists:project_modules,id']);
        DB::transaction(function () use ($request, $id, $programId, $data) {
            Project::lockForUpdate()->findOrFail($id);
            $program = Program::where('project_id', $id)->lockForUpdate()->findOrFail($programId);
            $module = ProjectModule::where('project_id', $id)->where('period_id', $program->period_id)->findOrFail($data['project_module_id']);
            $this->assertPeriodConfigurable($request, $program->period_id);
            abort_unless($module->training_id, 422, 'Önce modülü bir eğitime bağlayın.');
            abort_if($program->project_module_id && $program->project_module_id !== $module->id, 422, 'Bağlı oturum farklı modüle taşınamaz.');
            $program->update(['project_module_id' => $module->id]);
        });

        return response()->json(['message' => 'Oturum modüle bağlandı.']);
    }
}
