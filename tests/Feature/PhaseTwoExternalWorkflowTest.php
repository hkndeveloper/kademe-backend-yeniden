<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Period;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhaseTwoExternalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_social_webhook_acceptance_does_not_claim_platform_publication(): void
    {
        $this->actingSuperAdmin();
        SystemSetting::query()->create([
            'group' => 'social_media',
            'key' => 'sharing_webhook_url',
            'value' => 'https://webhook.test/share',
        ]);
        Http::fake(['https://webhook.test/share' => Http::response(['queued' => true], 202)]);

        $this->postJson('/api/panel/social-sharing/post', ['text' => 'Duyuru'])
            ->assertOk()
            ->assertJsonPath('webhook_accepted', true)
            ->assertJsonPath('publication_confirmed', false)
            ->assertJsonPath('message', 'Webhook icerigi kabul etti; sosyal medya yayini dogrulanmadi.');
        Http::assertSentCount(1);
    }

    public function test_social_webhook_failure_is_reported_as_failure(): void
    {
        $this->actingSuperAdmin();
        SystemSetting::query()->create([
            'group' => 'social_media',
            'key' => 'sharing_webhook_url',
            'value' => 'https://webhook.test/share',
        ]);
        Http::fake(['https://webhook.test/share' => Http::response(['error' => 'failed'], 500)]);

        $this->postJson('/api/panel/social-sharing/post', ['text' => 'Duyuru'])
            ->assertStatus(502)
            ->assertJsonPath('webhook_accepted', false)
            ->assertJsonPath('publication_confirmed', false);
    }

    public function test_waitlist_pilot_status_is_visible_without_enabling_automatic_sends(): void
    {
        $this->actingSuperAdmin();
        $pilot = Project::query()->create(['name' => 'Pilot', 'slug' => 'pilot-f2', 'type' => 'other', 'status' => 'active']);
        $other = Project::query()->create(['name' => 'Other', 'slug' => 'other-f2', 'type' => 'other', 'status' => 'active']);
        config()->set('application_waitlist.auto_schedule_enabled', false);
        config()->set('application_waitlist.auto_project_ids', [$pilot->id, $other->id]);

        $this->getJson('/api/panel/applications?project_id='.$pilot->id)
            ->assertOk()
            ->assertJsonPath('waitlist_automation.schedule_enabled', false)
            ->assertJsonPath('waitlist_automation.pilot_project_ids', [$pilot->id]);
    }

    public function test_waitlist_pilot_status_does_not_expose_other_project_scope(): void
    {
        $visible = Project::query()->create(['name' => 'Visible', 'slug' => 'visible-f2', 'type' => 'other', 'status' => 'active']);
        $hidden = Project::query()->create(['name' => 'Hidden', 'slug' => 'hidden-f2', 'type' => 'other', 'status' => 'active']);
        config()->set('application_waitlist.auto_schedule_enabled', true);
        config()->set('application_waitlist.auto_project_ids', [$visible->id, $hidden->id]);
        $actor = User::factory()->create(['role' => 'coordinator', 'surname' => 'PilotScope']);
        $visible->coordinators()->attach($actor->id);
        $role = Role::findOrCreate('coordinator', 'web');
        Permission::findOrCreate('applications.view', 'web');
        $role->givePermissionTo('applications.view');
        RolePermissionScope::query()->updateOrCreate(
            ['role_name' => 'coordinator', 'permission_name' => 'applications.view'],
            ['scope_type' => 'own_projects', 'scope_payload' => []]
        );
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        $this->getJson('/api/panel/applications')
            ->assertOk()
            ->assertJsonPath('waitlist_automation.pilot_project_ids', [$visible->id]);
    }

    public function test_google_external_events_are_paginated_deduplicated_and_read_only(): void
    {
        $actor = $this->actingSuperAdmin();
        config()->set('services.google_calendar', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://kademe.test/callback',
            'calendar_id' => 'primary',
            'external_read_enabled' => true,
        ]);
        foreach ([
            'google_calendar_refresh_token' => 'refresh-test',
            'google_calendar_access_token' => 'access-test',
            'google_calendar_token_expires_at' => now()->addHour()->toIso8601String(),
        ] as $key => $value) {
            SystemSetting::query()->create(['group' => 'google_calendar', 'key' => $key, 'value' => $value]);
        }
        CalendarEvent::query()->create([
            'title' => 'KADEME programı',
            'start_at' => '2026-10-02 10:00:00',
            'end_at' => '2026-10-02 11:00:00',
            'google_event_id' => 'local-program',
            'created_by' => $actor->id,
        ]);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::sequence()
            ->push([
                'items' => [
                    ['id' => 'local-program', 'summary' => 'KADEME programı', 'start' => ['dateTime' => '2026-10-02T10:00:00+03:00']],
                    ['id' => 'external-one', 'summary' => 'Harici toplantı', 'description' => 'Panelde paylaşılmamalı', 'start' => ['dateTime' => '2026-10-02T12:00:00+03:00'], 'end' => ['dateTime' => '2026-10-02T13:00:00+03:00']],
                ],
                'nextPageToken' => 'page-two',
            ], 200)
            ->push([
                'items' => [
                    ['id' => 'private-one', 'visibility' => 'private', 'summary' => 'Özel', 'start' => ['dateTime' => '2026-10-02T13:00:00+03:00']],
                    ['id' => 'external-two', 'summary' => 'Tam gün', 'start' => ['date' => '2026-10-03'], 'end' => ['date' => '2026-10-04']],
                ],
            ], 200)]);

        $this->getJson('/api/panel/calendar/google/external-events?start_at=2026-10-01T00:00:00%2B03:00&end_at=2026-10-05T00:00:00%2B03:00')
            ->assertOk()
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.google_event_id', 'external-one')
            ->assertJsonPath('events.1.google_event_id', 'external-two')
            ->assertJsonPath('events.1.all_day', true)
            ->assertJsonMissingPath('events.0.description')
            ->assertJsonPath('truncated', false);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_google_external_events_require_calendar_permission_and_bounded_range(): void
    {
        $student = User::factory()->create(['role' => 'student', 'surname' => 'NoCalendar']);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');
        Sanctum::actingAs($student);
        $url = '/api/calendar/google/external-events?start_at=2026-10-01&end_at=2026-10-05';
        $this->getJson($url)->assertForbidden();

        $this->actingSuperAdmin();
        Http::fake();
        $this->getJson('/api/panel/calendar/google/external-events?start_at=2026-10-01&end_at=2026-12-01')
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_google_external_read_is_off_until_calendar_visibility_is_approved(): void
    {
        config()->set('services.google_calendar.external_read_enabled', false);
        Http::fake();

        $result = app(GoogleCalendarService::class)->listExternalEvents(
            now()->startOfDay(),
            now()->addDays(7)->startOfDay()
        );

        $this->assertSame(['events' => [], 'truncated' => false], $result);
        Http::assertNothingSent();
    }

    public function test_program_creation_still_appears_in_calendar_and_syncs_outbound_to_google(): void
    {
        $this->actingSuperAdmin();
        $project = Project::query()->create([
            'name' => 'Takvim Akisi', 'slug' => 'takvim-akisi-f2', 'type' => 'other', 'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Donem',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $created = $this->postJson('/api/panel/programs', [
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Takvim Entegrasyon Testi',
            'start_at' => now()->addDay()->toIso8601String(),
            'end_at' => now()->addDay()->addHour()->toIso8601String(),
            'status' => 'scheduled',
        ])->assertCreated();
        $programId = $created->json('program.id');

        $this->getJson('/api/panel/calendar/overview?project_id='.$project->id)
            ->assertOk()
            ->assertJsonPath('programs.0.id', $programId)
            ->assertJsonPath('programs.0.title', 'Takvim Entegrasyon Testi');

        config()->set('services.google_calendar', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://kademe.test/callback',
            'calendar_id' => 'primary',
            'external_read_enabled' => false,
        ]);
        foreach ([
            'google_calendar_refresh_token' => 'refresh-test',
            'google_calendar_access_token' => 'access-test',
            'google_calendar_token_expires_at' => now()->addHour()->toIso8601String(),
        ] as $key => $value) {
            SystemSetting::query()->create(['group' => 'google_calendar', 'key' => $key, 'value' => $value]);
        }
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'outbound-program'], 200)]);

        $this->postJson('/api/panel/calendar/google/sync')
            ->assertOk()
            ->assertJsonPath('result.count', 1);
        $this->assertDatabaseHas('calendar_events', [
            'program_id' => $programId,
            'google_event_id' => 'outbound-program',
        ]);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/calendars/primary/events'));
    }

    private function actingSuperAdmin(): User
    {
        $user = User::factory()->create(['role' => 'super_admin', 'surname' => 'PhaseTwo']);
        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);

        return $user;
    }
}
