<?php

namespace Tests\Feature;

use App\Models\CommunicationLog;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailNotificationCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_threshold_drop_notifies_student_and_project_coordinator(): void
    {
        config()->set('services.resend.key', '');

        $student = User::factory()->create([
            'surname' => 'Student',
            'email' => 'credit-risk-student@test.local',
            'role' => 'student',
        ]);
        $coordinator = User::factory()->create([
            'surname' => 'Coordinator',
            'email' => 'credit-risk-coordinator@test.local',
            'role' => 'coordinator',
        ]);

        $project = Project::query()->create([
            'name' => 'Kredi Test Projesi',
            'slug' => 'kredi-test-'.Str::random(6),
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
            'created_by' => $coordinator->id,
        ]);
        $project->coordinators()->attach($coordinator->id);

        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'active',
        ]);

        $participant = Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 80,
            'enrolled_at' => now(),
        ]);

        app(CreditService::class)->deduct($participant, 10, 'Test devamsizlik');

        $this->assertDatabaseHas('participants', [
            'id' => $participant->id,
            'credit' => 70,
        ]);
        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $student->id,
            'type' => 'credit_low',
        ]);
        $this->assertSame(1, CommunicationLog::query()->where('subject', 'KADEME kredi uyarisi')->count());
        $this->assertSame(1, CommunicationLog::query()->where('subject', 'Kredi risk raporu')->count());
        $this->assertSame(1, SystemNotification::query()->where('type', 'credit_low')->count());
    }
}
