<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Project;
use App\Models\User;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Services\ApplicationConsentService;
use App\Services\NotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VolunteerCapacitySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create([
            'surname' => 'Gönüllü',
            'role' => $role,
            'status' => 'active',
            'kvkk_consent_at' => now(),
        ]);
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);

        return $user;
    }

    private function opportunity(int $quota): VolunteerOpportunity
    {
        $project = Project::query()->create([
            'name' => 'Gönüllülük Kontenjanı',
            'slug' => 'gonulluluk-kontenjani',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);

        return VolunteerOpportunity::query()->create([
            'project_id' => $project->id,
            'title' => 'Etkinlik gönüllüsü',
            'description' => 'Etkinlikte gönüllü çalışma',
            'quota' => $quota,
            'status' => 'open',
        ]);
    }

    private function application(VolunteerOpportunity $opportunity): VolunteerApplication
    {
        return VolunteerApplication::query()->create([
            'volunteer_opportunity_id' => $opportunity->id,
            'user_id' => $this->user('student')->id,
            'motivation_text' => 'Bu etkinlikte gönüllü olarak çalışmak istiyorum.',
            'status' => 'pending',
        ]);
    }

    public function test_later_acceptance_cannot_exceed_quota_and_rejected_decision_sends_no_email(): void
    {
        $opportunity = $this->opportunity(1);
        $first = $this->application($opportunity);
        $second = $this->application($opportunity);
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->once()
            ->andReturn(1);
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson("/api/panel/volunteer/applications/{$first->id}", ['status' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('application.status', 'accepted');
        $this->putJson("/api/panel/volunteer/applications/{$second->id}", ['status' => 'accepted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(1, VolunteerApplication::query()->where('status', 'accepted')->count());
        $this->assertDatabaseCount((new Participant)->getTable(), 0);
    }

    public function test_quota_edit_cannot_displace_accepted_volunteers_but_other_edits_and_released_seat_work(): void
    {
        $opportunity = $this->opportunity(2);
        $first = $this->application($opportunity);
        $second = $this->application($opportunity);
        $third = $this->application($opportunity);
        $first->update(['status' => 'accepted']);
        $second->update(['status' => 'accepted']);
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->andReturn(1);
        Sanctum::actingAs($this->user('super_admin'));

        $this->putJson("/api/panel/volunteer/opportunities/{$opportunity->id}", ['quota' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quota');
        $this->assertSame(2, $opportunity->fresh()->quota);

        $this->putJson("/api/panel/volunteer/opportunities/{$opportunity->id}", [
            'quota' => 2,
            'title' => 'Güncellenmiş ilan',
        ])->assertOk();
        $this->assertSame('Güncellenmiş ilan', $opportunity->fresh()->title);

        $this->putJson("/api/panel/volunteer/applications/{$second->id}", ['status' => 'rejected'])
            ->assertOk();
        $this->putJson("/api/panel/volunteer/opportunities/{$opportunity->id}", ['quota' => 1])
            ->assertOk();
        $this->putJson("/api/panel/volunteer/applications/{$third->id}", ['status' => 'accepted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->putJson("/api/panel/volunteer/opportunities/{$opportunity->id}", ['quota' => null])
            ->assertOk();
        $this->putJson("/api/panel/volunteer/applications/{$third->id}", ['status' => 'accepted'])
            ->assertOk();

        $this->assertSame(2, VolunteerApplication::query()->where('status', 'accepted')->count());
    }

    public function test_new_application_cannot_bypass_full_quota_after_panel_acceptance(): void
    {
        $opportunity = $this->opportunity(1);
        $first = $this->application($opportunity);
        $this->mock(NotificationService::class)
            ->shouldReceive('sendTemplatedEmail')
            ->once()
            ->andReturn(1);
        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson("/api/panel/volunteer/applications/{$first->id}", ['status' => 'accepted'])
            ->assertOk();

        Sanctum::actingAs($this->user('student'));
        $text = app(ApplicationConsentService::class)->textWithAdditional(null);
        $this->postJson("/api/volunteer/opportunities/{$opportunity->id}/apply", [
            'motivation_text' => 'Bu gönüllülük çalışmasına düzenli olarak destek vermek istiyorum.',
            'accepted_terms' => true,
            'expected_consent_text' => $text,
        ])->assertUnprocessable()->assertJsonValidationErrors('opportunity');

        $this->assertDatabaseCount('volunteer_applications', 1);
    }
}
