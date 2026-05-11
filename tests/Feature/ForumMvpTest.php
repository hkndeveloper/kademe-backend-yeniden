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

class ForumMvpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_student_can_create_list_and_reply_in_own_project_forum(): void
    {
        $student = User::factory()->create([
            'email' => 'forum-student@test.local',
            'surname' => 'Test',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $project = Project::query()->create([
            'name' => 'Forum Proje',
            'slug' => 'forum-proje',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->subWeek(),
            'end_date' => now()->addMonth(),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'active',
        ]);

        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $create = $this->postJson('/api/forum/posts', [
            'project_id' => $project->id,
            'title' => 'Forum basligi',
            'content' => 'Forum icerigi',
        ]);
        $create->assertCreated();
        $postId = (int) $create->json('post.id');

        $list = $this->getJson('/api/forum/posts');
        $list->assertOk();
        $this->assertCount(1, $list->json('posts.data'));

        $this->postJson("/api/forum/posts/{$postId}/replies", [
            'content' => 'Yanit metni',
        ])->assertCreated();

        $filtered = $this->getJson("/api/forum/posts?project_id={$project->id}");
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json('posts.data.0.replies'));
    }

    public function test_student_cannot_post_to_unjoined_project_forum(): void
    {
        $student = User::factory()->create([
            'email' => 'forum-student-2@test.local',
            'surname' => 'Test',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $project = Project::query()->create([
            'name' => 'Forum Proje 2',
            'slug' => 'forum-proje-2',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);

        Sanctum::actingAs($student);

        $this->postJson('/api/forum/posts', [
            'project_id' => $project->id,
            'title' => 'Baslik',
            'content' => 'Icerik',
        ])->assertForbidden();
    }
}
