<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\CreditLog;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ApplicationRestrictionReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_authorized_manager_sees_uncertain_history_and_must_record_reason_to_release(): void
    {
        [$candidate, $participant, $legacyProgram, $confirmedProgram] = $this->restrictedCandidate();
        $legacy = $this->deduction($participant, $legacyProgram, false);
        $this->deduction($participant, $confirmedProgram, true);
        Attendance::query()->create([
            'program_id' => $legacyProgram->id,
            'user_id' => $candidate->id,
            'method' => 'manual',
            'is_valid' => true,
        ]);
        Sanctum::actingAs($this->admin());

        $this->getJson("/api/panel/users/{$candidate->id}")
            ->assertOk()
            ->assertJsonPath('restriction_review.confirmed_absence_count', 1)
            ->assertJsonPath('restriction_review.unclassified_deduction_count', 1)
            ->assertJsonPath('restriction_review.unclassified_deductions.0.id', $legacy->id)
            ->assertJsonPath('restriction_review.unclassified_deductions.0.valid_attendance', true);

        $this->putJson("/api/panel/users/{$candidate->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restriction_review_reason');
        $this->assertSame('blacklisted', $candidate->fresh()->status);

        $this->putJson("/api/panel/users/{$candidate->id}", [
            'status' => 'active',
            'restriction_review_reason' => 'Eski programın geçerli yoklaması incelendi; geçici düşüm yaptırım sayılmadı.',
        ])->assertOk()->assertJsonPath('user.status', 'active');

        $this->assertNull($candidate->fresh()->blacklisted_until);
        $this->assertSame(1, (int) $candidate->fresh()->blacklist_count);
        $this->assertSame(100, (int) $participant->fresh()->credit);
        $this->assertDatabaseHas('credit_logs', ['id' => $legacy->id, 'absence_confirmed' => false]);
        $review = Activity::query()->where('log_name', 'application_restrictions')->where('subject_id', $candidate->id)->firstOrFail();
        $this->assertSame('application_restriction.reviewed', $review->description);
        $this->assertSame(1, $review->properties->get('unclassified_deduction_count'));
        $this->assertNotEmpty($review->properties->get('reason'));
        $this->getJson("/api/panel/users/{$candidate->id}")
            ->assertOk()
            ->assertJsonPath('restriction_review', null)
            ->assertJsonPath('restriction_reviews.0.id', $review->id);
        $this->putJson("/api/panel/users/{$candidate->id}", [
            'status' => 'active',
            'restriction_review_reason' => 'Aynı inceleme ikinci kez yazılmamalıdır.',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(1, Activity::query()->where('log_name', 'application_restrictions')->count());
    }

    public function test_reasonless_or_unauthorized_release_never_changes_restriction(): void
    {
        [$candidate] = $this->restrictedCandidate();
        Sanctum::actingAs($this->admin());
        $this->putJson("/api/panel/users/{$candidate->id}", [
            'status' => 'passive',
            'restriction_review_reason' => '          ',
        ])->assertUnprocessable();
        $this->assertSame('blacklisted', $candidate->fresh()->status);

        $student = User::factory()->create(['surname' => 'Yetkisiz', 'role' => 'student']);
        $student->assignRole('student');
        Sanctum::actingAs($student);
        $this->getJson("/api/panel/users/{$candidate->id}")->assertForbidden();
        $this->putJson("/api/panel/users/{$candidate->id}", [
            'status' => 'active',
            'restriction_review_reason' => 'Yetkisiz kullanıcı kısıtı kaldıramaz.',
        ])->assertForbidden();
        $this->assertSame('blacklisted', $candidate->fresh()->status);
        $this->assertSame(0, Activity::query()->where('log_name', 'application_restrictions')->count());
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['surname' => 'Yonetici', 'role' => 'super_admin']);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function restrictedCandidate(): array
    {
        $candidate = User::factory()->create([
            'surname' => 'Kisitli',
            'role' => 'student',
            'status' => 'blacklisted',
            'blacklisted_until' => now()->addMonths(2),
            'blacklist_count' => 1,
        ]);
        $project = Project::query()->create([
            'name' => 'Başvuru Kısıtı',
            'slug' => 'basvuru-kisiti-'.uniqid(),
            'type' => 'other',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $participant = Participant::query()->create([
            'user_id' => $candidate->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);
        $legacy = $this->program($project, $period, 'Eski program');
        $confirmed = $this->program($project, $period, 'Yeni program');

        return [$candidate, $participant, $legacy, $confirmed];
    }

    private function program(Project $project, Period $period, string $title): Program
    {
        return Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => $title,
            'start_at' => now()->subHours(2),
            'end_at' => now()->subHour(),
            'status' => 'completed',
        ]);
    }

    private function deduction(Participant $participant, Program $program, bool $confirmed): CreditLog
    {
        return CreditLog::query()->create([
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'project_id' => $participant->project_id,
            'period_id' => $participant->period_id,
            'program_id' => $program->id,
            'type' => 'deduction',
            'amount' => -10,
            'reason' => 'İncelenecek geçmiş kayıt',
            'absence_confirmed' => $confirmed,
        ]);
    }
}
