<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DirectMessageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config()->set('coordination_authorization.mode', 'enforce');
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_student_and_project_coordinator_can_exchange_private_project_messages(): void
    {
        Storage::fake('application_private');
        $student = $this->user('demo.student.p01@kademe.org');
        $coordinator = $this->user('demo.coordinator.p01@kademe.org');
        $outsider = $this->user('demo.coordinator.p02@kademe.org');
        $projectId = (int) Project::query()->whereHas('participants', fn ($query) => $query->where('user_id', $student->id))->firstOrFail()->id;

        Sanctum::actingAs($student);
        $this->getJson("/api/inbox/direct/recipients?project_id={$projectId}")
            ->assertOk()->assertJsonFragment(['id' => $coordinator->id]);
        $this->postJson('/api/inbox/direct/threads', [
            'project_id' => $projectId,
            'recipient_id' => $outsider->id,
            'subject' => 'Yanlis proje',
            'body' => 'Bu kisiye gonderilmemeli.',
        ])->assertForbidden();

        $created = $this->post('/api/inbox/direct/threads', [
            'project_id' => $projectId,
            'recipient_id' => $coordinator->id,
            'subject' => 'Gorusme',
            'body' => 'Ilk mesaj',
            'attachment' => UploadedFile::fake()->create('belge.pdf', 25, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $threadId = (int) $created->json('thread_id');
        $message = DirectMessage::query()->firstOrFail();
        Storage::disk('application_private')->assertExists($message->attachment_path);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/panel/inbox/direct/threads/{$threadId}")->assertForbidden();
        $this->get("/api/panel/inbox/direct/threads/{$threadId}/messages/{$message->id}/attachment")
            ->assertForbidden();

        Sanctum::actingAs($coordinator);
        $this->getJson('/api/panel/inbox/direct/threads')->assertOk()->assertJsonPath('threads.0.unread_count', 1);
        $this->getJson("/api/panel/inbox/direct/threads/{$threadId}")->assertOk()
            ->assertJsonPath('messages.0.body', 'Ilk mesaj');
        $this->getJson('/api/panel/inbox/direct/threads')->assertJsonPath('threads.0.unread_count', 0);
        $this->get("/api/panel/inbox/direct/threads/{$threadId}/messages/{$message->id}/attachment")
            ->assertOk();
        $this->postJson("/api/panel/inbox/direct/threads/{$threadId}/replies", ['body' => 'Koordinator yaniti'])
            ->assertCreated();

        Sanctum::actingAs($student);
        $this->getJson('/api/inbox/direct/threads')->assertJsonPath('threads.0.unread_count', 1);
        $this->getJson("/api/inbox/direct/threads/{$threadId}")->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.1.body', 'Koordinator yaniti');
        $this->postJson('/api/inbox/direct/threads', [
            'project_id' => $projectId,
            'recipient_id' => $this->user('demo.student.p02@kademe.org')->id,
            'subject' => 'Ogrenci', 'body' => 'Yasak',
        ])->assertForbidden();
    }

    public function test_message_attachment_and_body_limits_are_enforced(): void
    {
        Storage::fake('application_private');
        $student = $this->user('demo.student.p01@kademe.org');
        $coordinator = $this->user('demo.coordinator.p01@kademe.org');
        $projectId = (int) $student->participations()->firstOrFail()->project_id;
        Sanctum::actingAs($student);

        $this->post('/api/inbox/direct/threads', [
            'project_id' => $projectId, 'recipient_id' => $coordinator->id,
            'subject' => 'Sinir', 'body' => 'Deneme',
            'attachment' => UploadedFile::fake()->create('buyuk.pdf', 5121, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson('/api/inbox/direct/threads', [
            'project_id' => $projectId, 'recipient_id' => $coordinator->id,
            'subject' => 'Sinir', 'body' => str_repeat('x', 10001),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('direct_message_threads', 0);
    }

    public function test_authority_can_start_conversation_with_alumni_but_other_project_member_cannot_read_it(): void
    {
        $coordinator = $this->user('demo.coordinator.p01@kademe.org');
        $alumni = $this->user('demo.alumni.p01@kademe.org');
        $alumni->update(['status' => 'alumni']);
        $sameProjectStaff = $this->user('demo.staff.p01@kademe.org');
        $projectId = (int) $alumni->participations()->firstOrFail()->project_id;

        Sanctum::actingAs($coordinator);
        $this->getJson("/api/panel/inbox/direct/recipients?project_id={$projectId}")
            ->assertOk()->assertJsonFragment(['id' => $alumni->id]);
        $threadId = (int) $this->postJson('/api/panel/inbox/direct/threads', [
            'project_id' => $projectId,
            'recipient_id' => $alumni->id,
            'subject' => 'Mezun gorusmesi',
            'body' => 'Merhaba',
        ])->assertCreated()->json('thread_id');

        Sanctum::actingAs($sameProjectStaff);
        $this->getJson("/api/panel/inbox/direct/threads/{$threadId}")->assertForbidden();
        $this->postJson("/api/panel/inbox/direct/threads/{$threadId}/replies", ['body' => 'Yabanci mesaj'])
            ->assertForbidden();

        Sanctum::actingAs($alumni);
        $this->getJson("/api/inbox/direct/threads/{$threadId}")->assertOk()
            ->assertJsonPath('messages.0.body', 'Merhaba');
    }
}
