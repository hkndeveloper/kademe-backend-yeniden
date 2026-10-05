<?php

namespace Tests\Feature;

use App\Models\ForumPost;
use App\Models\Participant;
use App\Models\Period;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
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

    public function test_latest_forum_page_displays_topics_old_to_new_with_pinned_topics_first(): void
    {
        $student = User::factory()->create([
            'surname' => 'Forum', 'role' => 'student', 'kvkk_consent_at' => now(),
        ]);
        $student->assignRole('student');
        $project = Project::query()->create([
            'name' => 'Ordered Forum', 'slug' => 'ordered-forum', 'type' => 'other', 'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id, 'name' => 'Current', 'status' => 'active',
            'start_date' => now()->subMonth(), 'end_date' => now()->addMonth(),
        ]);
        Participant::query()->create([
            'user_id' => $student->id, 'project_id' => $project->id,
            'period_id' => $period->id, 'status' => 'active',
        ]);

        $posts = [];
        for ($number = 1; $number <= 21; $number++) {
            $post = ForumPost::query()->create([
                'project_id' => $project->id, 'period_id' => $period->id,
                'user_id' => $student->id, 'title' => "Topic {$number}",
                'content' => "Message {$number}", 'is_pinned' => $number === 1,
            ]);
            $post->forceFill(['created_at' => now()->subMinutes(30 - $number)])->save();
            $posts[] = $post;
        }

        Sanctum::actingAs($student);
        $this->getJson('/api/forum/posts')
            ->assertOk()
            ->assertJsonPath('posts.total', 21)
            ->assertJsonCount(20, 'posts.data')
            ->assertJsonPath('posts.data.0.id', $posts[0]->id)
            ->assertJsonPath('posts.data.1.id', $posts[2]->id)
            ->assertJsonPath('posts.data.19.id', $posts[20]->id);
        $this->getJson('/api/forum/posts?page=2')
            ->assertOk()
            ->assertJsonPath('posts.data.0.id', $posts[1]->id);

        $admin = User::factory()->create(['surname' => 'Admin', 'role' => 'super_admin']);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);
        $this->getJson('/api/panel/forum/posts')
            ->assertOk()
            ->assertJsonPath('posts.data.0.id', $posts[0]->id)
            ->assertJsonPath('posts.data.19.id', $posts[20]->id);
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

    public function test_forum_moderator_can_pin_only_topics_in_own_project_scope_and_action_is_audited(): void
    {
        $allowed = Project::query()->create([
            'name' => 'Moderation Allowed', 'slug' => 'moderation-allowed', 'type' => 'other', 'status' => 'active',
        ]);
        $other = Project::query()->create([
            'name' => 'Moderation Other', 'slug' => 'moderation-other', 'type' => 'other', 'status' => 'active',
        ]);
        $student = User::factory()->create(['role' => 'student', 'surname' => 'Writer']);
        $allowedPost = ForumPost::query()->create([
            'project_id' => $allowed->id, 'user_id' => $student->id,
            'title' => 'Allowed topic', 'content' => 'Visible content', 'is_pinned' => false,
        ]);
        $otherPost = ForumPost::query()->create([
            'project_id' => $other->id, 'user_id' => $student->id,
            'title' => 'Other topic', 'content' => 'Visible content', 'is_pinned' => false,
        ]);

        $moderator = User::factory()->create(['role' => 'coordinator', 'surname' => 'Moderator']);
        $role = Role::findOrCreate('forum_project_moderator', 'web');
        foreach (['forum.view', 'forum.moderate'] as $permissionName) {
            $role->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
            RolePermissionScope::query()->create([
                'role_name' => $role->name,
                'permission_name' => $permissionName,
                'scope_type' => 'selected_projects',
                'scope_payload' => ['project_ids' => [$allowed->id]],
            ]);
        }
        $moderator->assignRole($role);

        Sanctum::actingAs($student);
        $this->putJson("/api/panel/forum/posts/{$allowedPost->id}/pin", ['is_pinned' => true])->assertForbidden();

        Sanctum::actingAs($moderator);
        $this->getJson('/api/panel/forum/posts')->assertOk()->assertJsonCount(1, 'posts.data');
        $this->putJson("/api/panel/forum/posts/{$otherPost->id}/pin", ['is_pinned' => true])->assertForbidden();
        $this->putJson("/api/panel/forum/posts/{$allowedPost->id}/pin", ['is_pinned' => 'invalid'])->assertUnprocessable();
        $this->putJson("/api/panel/forum/posts/{$allowedPost->id}/pin", ['is_pinned' => true])
            ->assertOk()->assertJsonPath('post.is_pinned', true);
        $this->assertTrue($allowedPost->fresh()->is_pinned);
        $this->assertFalse($otherPost->fresh()->is_pinned);
        $this->putJson("/api/panel/forum/posts/{$allowedPost->id}/pin", ['is_pinned' => false])
            ->assertOk()->assertJsonPath('post.is_pinned', false);
        $this->assertFalse($allowedPost->fresh()->is_pinned);

        $audit = Activity::query()->where('event', 'forum.post.pin_updated')->latest('id')->firstOrFail();
        $this->assertSame($moderator->id, $audit->causer_id);
        $this->assertSame($allowedPost->id, $audit->subject_id);
        $this->assertSame(false, $audit->properties->get('domain')['is_pinned_after']);
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
