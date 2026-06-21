<?php

namespace Tests\Feature;

use App\Models\ForumPost;
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

    public function test_forum_posts_can_be_scoped_to_participant_period(): void
    {
        $student = User::factory()->create([
            'email' => 'forum-period@test.local',
            'surname' => 'Test',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $project = Project::query()->create([
            'name' => 'Forum Donem Proje',
            'slug' => 'forum-donem-proje',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);
        $activePeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Forum Donemi',
            'start_date' => now()->subWeek(),
            'end_date' => now()->addMonth(),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'active',
        ]);
        $otherPeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Baska Forum Donemi',
            'start_date' => now()->subMonths(5),
            'end_date' => now()->subMonths(4),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'passive',
        ]);

        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'status' => 'active',
        ]);

        ForumPost::query()->create([
            'project_id' => $project->id,
            'period_id' => null,
            'user_id' => $student->id,
            'title' => 'Proje Geneli Konu',
            'content' => 'Genel forum icerigi',
        ]);
        ForumPost::query()->create([
            'project_id' => $project->id,
            'period_id' => $otherPeriod->id,
            'user_id' => $student->id,
            'title' => 'Baska Donem Konusu',
            'content' => 'Baska donem forum icerigi',
        ]);

        Sanctum::actingAs($student);

        $create = $this->postJson('/api/forum/posts', [
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'title' => 'Aktif Donem Konusu',
            'content' => 'Aktif donem forum icerigi',
        ]);
        $create->assertCreated()
            ->assertJsonPath('post.period.id', $activePeriod->id);

        $filtered = $this->getJson("/api/forum/posts?project_id={$project->id}&period_id={$activePeriod->id}")
            ->assertOk();

        $titles = collect($filtered->json('posts.data'))->pluck('title')->all();
        $this->assertContains('Proje Geneli Konu', $titles);
        $this->assertContains('Aktif Donem Konusu', $titles);
        $this->assertNotContains('Baska Donem Konusu', $titles);

        $this->postJson('/api/forum/posts', [
            'project_id' => $project->id,
            'period_id' => $otherPeriod->id,
            'title' => 'Yetkisiz Donem',
            'content' => 'Bu doneme yazamamali.',
        ])->assertForbidden();
    }

    public function test_completed_period_forum_is_read_only_for_participants(): void
    {
        $student = User::factory()->create([
            'email' => 'forum-completed@test.local',
            'surname' => 'Test',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $project = Project::query()->create([
            'name' => 'Forum Arsiv Proje',
            'slug' => 'forum-arsiv-proje',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);
        $completedPeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Tamamlanmis Forum Donemi',
            'start_date' => now()->subMonths(5),
            'end_date' => now()->subMonths(4),
            'credit_start_amount' => 100,
            'credit_threshold' => 75,
            'status' => 'completed',
        ]);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'status' => 'graduated',
            'graduation_status' => 'graduated',
            'graduated_at' => now()->subMonth(),
        ]);
        $post = ForumPost::query()->create([
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'user_id' => $student->id,
            'title' => 'Arsiv Konusu',
            'content' => 'Arsivde okunabilir.',
        ]);

        Sanctum::actingAs($student);

        $this->getJson("/api/forum/posts?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonPath('posts.data.0.title', 'Arsiv Konusu');

        $this->postJson('/api/forum/posts', [
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'title' => 'Arsive Yeni Konu',
            'content' => 'Tamamlanmis doneme yazilmamali.',
        ])->assertStatus(423);

        $this->postJson("/api/forum/posts/{$post->id}/replies", [
            'content' => 'Arsive yeni yanit yazilmamali.',
        ])->assertStatus(423);
    }
}
