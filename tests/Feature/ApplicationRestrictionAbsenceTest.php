<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\ProgramAbsence;
use App\Models\Project;
use App\Models\User;
use App\Services\CreditService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationRestrictionAbsenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendEmail')->andReturn(1)->byDefault();
        });
    }

    public function test_attended_programs_do_not_become_absences_while_feedback_is_pending(): void
    {
        $participant = $this->participant(150);
        $service = app(CreditService::class);

        foreach (range(1, 3) as $number) {
            $program = $this->program($participant, $number);
            Attendance::query()->create([
                'program_id' => $program->id,
                'user_id' => $participant->user_id,
                'method' => 'manual',
                'is_valid' => true,
            ]);
            $service->deductOnceForProgram($participant, $program, null, null, true);
        }

        $this->assertSame(0, $service->confirmedAbsenceCount($participant));
        $this->assertSame('active', $participant->user->fresh()->status);
        $this->assertSame(120, (int) $participant->fresh()->credit);
        $this->assertSame(0, CreditLog::query()->where('absence_confirmed', true)->count());
    }

    public function test_three_confirmed_absences_restrict_applications_even_above_credit_warning_threshold(): void
    {
        $participant = $this->participant(150);
        $service = app(CreditService::class);

        foreach (range(1, 3) as $number) {
            $service->deductOnceForProgram($participant, $this->program($participant, $number), null, null, false);
        }

        $this->assertSame(3, $service->confirmedAbsenceCount($participant));
        $this->assertSame(120, (int) $participant->fresh()->credit);
        $this->assertSame('blacklisted', $participant->user->fresh()->status);
        $this->assertNotNull($participant->user->fresh()->blacklisted_until);
        $this->assertSame(1, (int) $participant->user->fresh()->blacklist_count);
    }

    public function test_restored_excused_corrected_and_legacy_deductions_do_not_count(): void
    {
        $participant = $this->participant(200);
        $service = app(CreditService::class);

        $restored = $this->program($participant, 1);
        $service->deductOnceForProgram($participant, $restored, null, null, false);
        $service->restoreOnceForFeedback($participant, $restored);

        $excused = $this->program($participant, 2);
        $excusedLog = $service->deductOnceForProgram($participant, $excused, null, null, false);
        $service->markExcused($excusedLog);

        $corrected = $this->program($participant, 3);
        $service->deductOnceForProgram($participant, $corrected, null, null, false);
        Attendance::query()->create([
            'program_id' => $corrected->id,
            'user_id' => $participant->user_id,
            'method' => 'manual',
            'is_valid' => true,
        ]);

        $legacy = $this->program($participant, 4);
        CreditLog::query()->create([
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'project_id' => $participant->project_id,
            'period_id' => $participant->period_id,
            'program_id' => $legacy->id,
            'amount' => -10,
            'type' => 'deduction',
            'reason' => 'Eski kayit',
        ]);

        $realAbsence = $this->program($participant, 5);
        $service->deductOnceForProgram($participant, $realAbsence, null, null, false);

        $this->assertSame(1, $service->confirmedAbsenceCount($participant));
        $this->assertSame('active', $participant->user->fresh()->status);
        $this->assertFalse((bool) CreditLog::query()->where('program_id', $legacy->id)->firstOrFail()->absence_confirmed);
    }

    public function test_old_restriction_is_not_automatically_revoked_after_attendance_correction(): void
    {
        $participant = $this->participant(150);
        $service = app(CreditService::class);
        $programs = [];
        foreach (range(1, 3) as $number) {
            $programs[] = $program = $this->program($participant, $number);
            $service->deductOnceForProgram($participant, $program, null, null, false);
        }

        Attendance::query()->create([
            'program_id' => $programs[0]->id,
            'user_id' => $participant->user_id,
            'method' => 'manual',
            'is_valid' => true,
        ]);

        $this->assertSame(2, $service->confirmedAbsenceCount($participant));
        $this->assertSame('blacklisted', $participant->user->fresh()->status);
    }

    public function test_zero_credit_program_absences_count_without_changing_credit_or_creating_credit_logs(): void
    {
        $participant = $this->participant(150);
        $service = app(CreditService::class);
        foreach (range(1, 3) as $number) {
            $program = $this->program($participant, $number);
            $program->update(['credit_deduction' => 0]);
            $service->deductOnceForProgram($participant, $program, null, null, false);
            $service->deductOnceForProgram($participant, $program, null, null, false);
        }

        $this->assertSame(3, ProgramAbsence::query()->count());
        $this->assertSame(3, $service->confirmedAbsenceCount($participant));
        $this->assertSame(0, CreditLog::query()->count());
        $this->assertSame(150, (int) $participant->fresh()->credit);
        $this->assertSame('blacklisted', $participant->user->fresh()->status);
    }

    public function test_zero_credit_absence_respects_valid_attendance_excuse_and_later_correction(): void
    {
        $participant = $this->participant(150);
        $service = app(CreditService::class);
        $program = $this->program($participant, 1);
        $program->update(['credit_deduction' => 0]);
        Attendance::query()->create([
            'program_id' => $program->id, 'user_id' => $participant->user_id,
            'method' => 'manual', 'is_valid' => true,
        ]);
        $service->deductOnceForProgram($participant, $program, null, null, false);
        $this->assertSame(0, ProgramAbsence::query()->count());

        Attendance::query()->where('program_id', $program->id)->update(['is_valid' => false]);
        $service->reconcileCompletedProgramAttendance($program, $participant, false, $participant->user_id);
        $absence = ProgramAbsence::query()->firstOrFail();
        $this->assertSame(1, $service->confirmedAbsenceCount($participant));

        $service->markProgramAbsenceExcused($absence, true, 'Belgelendirilen mazeret kabul edildi.', $participant->user);
        $this->assertSame(0, $service->confirmedAbsenceCount($participant));
        $service->markProgramAbsenceExcused($absence->fresh(), false, 'Mazeret kararı yeniden incelendi.', $participant->user);
        $this->assertSame(1, $service->confirmedAbsenceCount($participant));

        Attendance::query()->where('program_id', $program->id)->update(['is_valid' => true]);
        $this->assertSame(0, $service->confirmedAbsenceCount($participant));
        $this->assertSame(150, (int) $participant->fresh()->credit);
    }

    private function participant(int $credit): Participant
    {
        $user = User::factory()->create(['surname' => 'Aday', 'role' => 'student', 'status' => 'active']);
        $project = Project::query()->create([
            'name' => 'Kisitlama Testi',
            'slug' => 'kisitlama-test-'.uniqid(),
            'type' => 'other',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'credit_threshold' => 75,
            'status' => 'active',
        ]);

        return Participant::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => $credit,
        ])->load(['user', 'period']);
    }

    private function program(Participant $participant, int $number): Program
    {
        return Program::query()->create([
            'project_id' => $participant->project_id,
            'period_id' => $participant->period_id,
            'title' => 'Oturum '.$number,
            'start_at' => now()->subDays($number)->subHour(),
            'end_at' => now()->subDays($number),
            'credit_deduction' => 10,
            'status' => 'completed',
        ]);
    }
}
