<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KademePlusModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_coordinator_can_create_kademe_module_and_student_can_enroll_and_see_leaderboard(): void
    {
        $admin = User::factory()->create([
            'name' => 'Ust',
            'surname' => 'Admin',
            'email' => 'admin-kp-mod@test.local',
            'role' => 'super_admin',
        ]);
        Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');

        $student = User::factory()->create([
            'name' => 'Ogr',
            'surname' => 'Test',
            'email' => 'student-kp-mod@test.local',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $project = Project::query()->create([
            'name' => 'KADEME+',
            'slug' => 'kademe-plus-test',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);

        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Guz',
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonths(6),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'active',
        ]);

        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
            'credit' => 100,
        ]);

        Sanctum::actingAs($admin);

        $create = $this->postJson("/api/admin/projects/{$project->id}/special-modules/kademe-modules", [
            'title' => 'Modul 1',
            'description' => 'Aciklama',
            'outcomes' => ['Kazanim A'],
            'instructors' => [['name' => 'Egitmen', 'bio' => 'Bio']],
            'faq_items' => [['question' => 'Soru?', 'answer' => 'Cevap']],
            'warning_text' => 'Uyari metni',
            'consent_checkbox_label' => 'Okudum',
            'requires_coordinator_approval' => false,
        ]);

        $create->assertCreated();
        $moduleId = $create->json('kademe_module.id');
        $this->assertNotNull($moduleId);

        $index = $this->getJson("/api/admin/projects/{$project->id}/special-modules");
        $index->assertOk();
        $this->assertNotEmpty($index->json('kademe_modules'));

        Sanctum::actingAs($student);

        $specials = $this->getJson('/api/dashboard/project-specials');
        $specials->assertOk();
        $projects = collect($specials->json('projects'));
        $row = $projects->firstWhere('project.id', $project->id);
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['kademe_modules']);

        $enroll = $this->postJson("/api/dashboard/projects/{$project->id}/kademe-modules/{$moduleId}/enroll", [
            'accepted_terms' => true,
        ]);
        $enroll->assertCreated();
        $this->assertSame('approved', $enroll->json('enrollment.status'));

        $board = $this->getJson("/api/dashboard/projects/{$project->id}/badge-leaderboard");
        $board->assertOk();
        $this->assertNotEmpty($board->json('leaderboard'));
        $this->assertSame($student->id, $board->json('me.user_id'));
    }
}
