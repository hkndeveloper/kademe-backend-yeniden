<?php

namespace Tests\Feature;

use App\Enums\PeriodWriteAction;
use App\Models\Period;
use App\Models\Project;
use App\Services\PeriodWritePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PeriodModuleWriteMatrixTest extends TestCase
{
    use RefreshDatabase;

    public static function moduleActions(): array
    {
        return [
            'application intake create' => ['application_intake', PeriodWriteAction::CREATE_OPERATION],
            'program create' => ['programs', PeriodWriteAction::CREATE_OPERATION],
            'assignment create' => ['assignments', PeriodWriteAction::CREATE_OPERATION],
            'announcement create' => ['announcements', PeriodWriteAction::CREATE_OPERATION],
            'financial transaction create' => ['financial_transactions', PeriodWriteAction::CREATE_OPERATION],
            'volunteer opportunity create' => ['volunteer_opportunities', PeriodWriteAction::CREATE_OPERATION],
            'digital bohca create' => ['digital_bohca', PeriodWriteAction::CREATE_OPERATION],
            'kpd appointment create' => ['kpd_appointments', PeriodWriteAction::CREATE_OPERATION],
            'support ticket create' => ['support_tickets', PeriodWriteAction::CREATE_OPERATION],
            'service request create' => ['requests', PeriodWriteAction::CREATE_OPERATION],
            'project special module create' => ['project_special_modules', PeriodWriteAction::CREATE_OPERATION],
            'calendar meeting create' => ['calendar_meetings', PeriodWriteAction::CREATE_OPERATION],
            'application decision' => ['application_decisions', PeriodWriteAction::RESOLVE_OPERATION],
            'waitlist invitation' => ['waitlist', PeriodWriteAction::RESOLVE_OPERATION],
            'program completion' => ['program_completion', PeriodWriteAction::RESOLVE_OPERATION],
            'attendance and qr' => ['attendance_qr', PeriodWriteAction::RESOLVE_OPERATION],
            'feedback and credit restore' => ['feedback', PeriodWriteAction::RESOLVE_OPERATION],
            'assignment review' => ['assignment_reviews', PeriodWriteAction::RESOLVE_OPERATION],
            'financial approval and payment' => ['financial_resolution', PeriodWriteAction::RESOLVE_OPERATION],
            'volunteer application decision' => ['volunteer_application_decisions', PeriodWriteAction::RESOLVE_OPERATION],
            'certificate delivery' => ['certificates', PeriodWriteAction::RESOLVE_OPERATION],
            'kpd report and status' => ['kpd_resolution', PeriodWriteAction::RESOLVE_OPERATION],
            'participant graduation' => ['participant_graduation', PeriodWriteAction::RESOLVE_OPERATION],
            'manual credit and background deduction' => ['credits', PeriodWriteAction::RESOLVE_OPERATION],
            'support reply and close' => ['support_resolution', PeriodWriteAction::RESOLVE_OPERATION],
            'request response and close' => ['request_resolution', PeriodWriteAction::RESOLVE_OPERATION],
            'calendar assignment and sync' => ['calendar_resolution', PeriodWriteAction::RESOLVE_OPERATION],
            'forum post and reply' => ['forum', PeriodWriteAction::RESOLVE_OPERATION],
        ];
    }

    #[DataProvider('moduleActions')]
    public function test_every_period_module_obeys_active_closing_completed_matrix(
        string $module,
        PeriodWriteAction $action,
    ): void {
        $projectSlug = 'module-matrix-'.str($module)->slug();
        $active = $this->period($this->project($projectSlug.'-active'), 'active');
        $closing = $this->period($this->project($projectSlug.'-closing'), 'closing');
        $completed = $this->period($this->project($projectSlug.'-completed'), 'completed');
        $policy = app(PeriodWritePolicy::class);

        $this->assertSame(
            $action->value,
            $policy->assertAllowed(null, $active, $action)['mode'],
            "{$module} aktif donemde calismalidir.",
        );

        if ($action === PeriodWriteAction::RESOLVE_OPERATION) {
            $this->assertSame(
                PeriodWriteAction::RESOLVE_OPERATION->value,
                $policy->assertAllowed(null, $closing, $action)['mode'],
                "{$module} kapanista mevcut isi sonuclandirabilmelidir.",
            );
        } else {
            $this->assertLocked(
                fn () => $policy->assertAllowed(null, $closing, $action),
                "{$module} kapanista yeni kayit acamamaliydi.",
            );
        }

        $this->assertLocked(
            fn () => $policy->assertAllowed(null, $completed, $action),
            "{$module} tamamlanmis donemde salt okunur olmaliydi.",
        );
    }

    private function assertLocked(callable $callback, string $failureMessage): void
    {
        try {
            $callback();
            $this->fail($failureMessage);
        } catch (HttpException $exception) {
            $this->assertSame(423, $exception->getStatusCode(), $failureMessage);
        }
    }

    private function project(string $slug): Project
    {
        return Project::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function period(Project $project, string $status): Period
    {
        return Period::query()->create([
            'project_id' => $project->id,
            'name' => strtoupper($status).' Donemi',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => $status,
        ]);
    }
}
