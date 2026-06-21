<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use App\Support\MediaStorage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');
    }

    public function test_student_ticket_attachment_uses_media_storage_and_is_downloadable(): void
    {
        $student = User::factory()->create([
            'surname' => 'Student',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);

        $createResponse = $this->post('/api/tickets', [
            'subject' => 'Resmi evrak talebi',
            'category' => 'resmi_evrak',
            'message' => 'Okula teslim edilecek resmi belge icin destek istiyorum.',
            'attachment' => UploadedFile::fake()->create('talep.pdf', 16, 'application/pdf'),
        ])->assertCreated();

        $ticketId = $createResponse->json('ticket.id');
        $ticket = SupportTicket::query()->findOrFail($ticketId);

        $this->assertNotNull($ticket->attachment_path);
        $this->assertTrue(MediaStorage::exists($ticket->attachment_path));
        $this->assertSame("/tickets/{$ticketId}/attachment", $createResponse->json('ticket.attachment_download_url'));

        $this->get("/api/tickets/{$ticketId}/attachment")
            ->assertOk();
    }

    public function test_official_document_ticket_requires_attachment_for_students(): void
    {
        $student = User::factory()->create([
            'surname' => 'Student',
            'role' => 'student',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);

        $this->postJson('/api/tickets', [
            'subject' => 'Resmi evrak talebi',
            'category' => 'resmi_evrak',
            'message' => 'Evrak icin destek istiyorum.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');
    }
}
