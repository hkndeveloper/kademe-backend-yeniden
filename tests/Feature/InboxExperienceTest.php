<?php

namespace Tests\Feature;

use App\Models\AlumniOpportunity;
use App\Models\Announcement;
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

class InboxExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_recipient_inbox_returns_unified_payload_and_supports_state_filters(): void
    {
        $student = User::factory()->create([
            'email' => 'inbox-student@test.local',
            'surname' => 'Student',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $other = User::factory()->create([
            'email' => 'inbox-other@test.local',
            'surname' => 'Other',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        $other->assignRole('student');

        $project = Project::query()->create([
            'name' => 'Inbox Proje',
            'slug' => 'inbox-proje',
            'type' => 'kademe_plus',
            'status' => 'active',
            'application_open' => true,
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026',
            'start_date' => now()->subWeek(),
            'end_date' => now()->addMonth(),
            'status' => 'active',
        ]);
        Participant::query()->create([
            'user_id' => $student->id,
            'project_id' => $project->id,
            'period_id' => $period->id,
            'status' => 'active',
        ]);

        Announcement::query()->create([
            'title' => 'Duyuru',
            'content' => 'Icerik',
            'category' => 'system',
            'target_roles' => ['student'],
            'project_id' => $project->id,
            'created_by' => $other->id,
            'published_at' => now()->subHour(),
        ]);

        AlumniOpportunity::query()->create([
            'title' => 'Firsat',
            'kind' => 'internship',
            'summary' => 'Staj firsati',
            'project_id' => $project->id,
            'created_by' => $other->id,
            'published_at' => now()->subHour(),
            'target_audience' => ['student'],
        ]);

        ForumPost::query()->create([
            'project_id' => $project->id,
            'user_id' => $other->id,
            'title' => 'Forum Konu',
            'content' => 'Forum icerigi',
        ]);

        Sanctum::actingAs($student);

        $index = $this->getJson('/api/inbox/messages');
        $index->assertOk();
        $items = $index->json('messages');
        $types = collect($items)->pluck('type')->unique()->values()->all();
        $this->assertContains('announcement', $types);
        $this->assertContains('opportunity', $types);
        $this->assertContains('forum_post', $types);

        $announcementItem = collect($items)->firstWhere('type', 'announcement');
        $this->assertNotNull($announcementItem);

        $this->putJson('/api/inbox/messages/state', [
            'source_type' => $announcementItem['source_type'],
            'source_id' => $announcementItem['source_id'],
            'is_read' => true,
            'is_starred' => true,
            'is_pinned' => true,
        ])->assertOk();

        $starred = $this->getJson('/api/inbox/messages?starred_only=1');
        $starred->assertOk()->assertJsonCount(1, 'messages');

        $unread = $this->getJson('/api/inbox/messages?unread_only=1');
        $unread->assertOk();
        $this->assertFalse(
            collect($unread->json('messages'))->contains(fn ($msg) => $msg['source_type'] === $announcementItem['source_type'] && (int) $msg['source_id'] === (int) $announcementItem['source_id'])
        );
    }

    public function test_panel_super_admin_can_see_project_scoped_messages_in_inbox(): void
    {
        $admin = User::factory()->create([
            'email' => 'inbox-admin@test.local',
            'surname' => 'Admin',
            'role' => 'super_admin',
        ]);
        Role::findOrCreate('super_admin', 'web');
        $admin->assignRole('super_admin');

        $author = User::factory()->create([
            'email' => 'inbox-author@test.local',
            'surname' => 'Author',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $author->assignRole('student');

        $project = Project::query()->create([
            'name' => 'Panel Inbox Proje',
            'slug' => 'panel-inbox-proje',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
        ]);

        Announcement::query()->create([
            'title' => 'Panel Duyuru',
            'content' => 'Panel duyuru icerigi',
            'category' => 'system',
            'target_roles' => ['student'],
            'project_id' => $project->id,
            'created_by' => $author->id,
            'published_at' => now()->subHour(),
        ]);

        AlumniOpportunity::query()->create([
            'title' => 'Panel Firsat',
            'kind' => 'event',
            'summary' => 'Panel firsat icerigi',
            'project_id' => $project->id,
            'created_by' => $author->id,
            'published_at' => now()->subHour(),
            'target_audience' => ['student'],
        ]);

        ForumPost::query()->create([
            'project_id' => $project->id,
            'user_id' => $author->id,
            'title' => 'Panel Forum',
            'content' => 'Panel forum icerigi',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/panel/inbox/messages');
        $response->assertOk();
        $types = collect($response->json('messages'))->pluck('type')->unique()->values()->all();
        $this->assertContains('announcement', $types);
        $this->assertContains('opportunity', $types);
        $this->assertContains('forum_post', $types);
    }

    public function test_user_cannot_update_state_for_inaccessible_message_source(): void
    {
        $student = User::factory()->create([
            'email' => 'inbox-student-deny@test.local',
            'surname' => 'Student',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        $otherProject = Project::query()->create([
            'name' => 'Diger Proje',
            'slug' => 'diger-proje',
            'type' => 'other',
            'status' => 'active',
            'application_open' => true,
        ]);
        $creator = User::factory()->create([
            'email' => 'inbox-creator-deny@test.local',
            'surname' => 'Creator',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        $creator->assignRole('student');

        $announcement = Announcement::query()->create([
            'title' => 'Erisilemeyen Duyuru',
            'content' => 'Gorunmemeli',
            'category' => 'system',
            'target_roles' => ['student'],
            'project_id' => $otherProject->id,
            'created_by' => $creator->id,
            'published_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($student);
        $this->putJson('/api/inbox/messages/state', [
            'source_type' => Announcement::class,
            'source_id' => $announcement->id,
            'is_starred' => true,
        ])->assertForbidden();
    }
}
