<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarOverviewFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_calendar_overview_applies_project_and_period_filters_on_backend(): void
    {
        Permission::findOrCreate('calendar.view', 'web');
        $role = Role::findOrCreate('calendar_filter_viewer', 'web');
        $role->givePermissionTo('calendar.view');

        RolePermissionScope::query()->create([
            'role_name' => 'calendar_filter_viewer',
            'permission_name' => 'calendar.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $viewer = User::factory()->create([
            'name' => 'Calendar',
            'surname' => 'Viewer',
            'role' => 'coordinator',
        ]);
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);

        $firstProject = Project::query()->create([
            'name' => 'Birinci Proje',
            'slug' => 'birinci-proje',
            'type' => 'other',
            'status' => 'active',
        ]);
        $secondProject = Project::query()->create([
            'name' => 'Ikinci Proje',
            'slug' => 'ikinci-proje',
            'type' => 'other',
            'status' => 'active',
        ]);

        $firstPeriod = Period::query()->create([
            'project_id' => $firstProject->id,
            'name' => '2026 Ilk',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $secondPeriod = Period::query()->create([
            'project_id' => $firstProject->id,
            'name' => '2026 Ikinci',
            'start_date' => now()->addMonths(2)->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'status' => 'completed',
        ]);
        $otherPeriod = Period::query()->create([
            'project_id' => $secondProject->id,
            'name' => '2026 Diger',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $this->createProgram($firstProject, $firstPeriod, 'Filtrelenen Program');
        $this->createProgram($firstProject, $secondPeriod, 'Ayni Proje Diger Donem');
        $this->createProgram($secondProject, $otherPeriod, 'Diger Proje Programi');
        SystemSetting::query()->create([
            'key' => 'google_calendar_last_error',
            'value' => 'Token yenilenemedi',
            'group' => 'google_calendar',
        ]);
        SystemSetting::query()->create([
            'key' => 'google_calendar_last_error_at',
            'value' => now()->toIso8601String(),
            'group' => 'google_calendar',
        ]);

        $this->getJson("/api/panel/calendar/overview?project_id={$firstProject->id}&period_id={$firstPeriod->id}")
            ->assertOk()
            ->assertJsonCount(1, 'programs')
            ->assertJsonPath('programs.0.title', 'Filtrelenen Program')
            ->assertJsonPath('summary.total_programs', 1)
            ->assertJsonPath('google_calendar.last_error', 'Token yenilenemedi')
            ->assertJsonStructure(['google_calendar' => ['last_error_at']]);

        $this->getJson("/api/panel/calendar/overview?project_id={$firstProject->id}")
            ->assertOk()
            ->assertJsonCount(2, 'programs')
            ->assertJsonPath('summary.total_programs', 2);

        $this->getJson("/api/panel/calendar/overview?project_id={$firstProject->id}&period_id={$secondPeriod->id}")
            ->assertOk()
            ->assertJsonCount(1, 'programs')
            ->assertJsonPath('programs.0.title', 'Ayni Proje Diger Donem')
            ->assertJsonPath('summary.total_programs', 1);

        $this->getJson("/api/panel/calendar/overview?project_id={$firstProject->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    public function test_calendar_meeting_can_be_created_and_seen_by_invited_user(): void
    {
        $admin = User::factory()->create([
            'name' => 'Calendar',
            'surname' => 'Admin',
            'role' => 'super_admin',
        ]);
        $admin->assignRole('super_admin');

        $invitee = User::factory()->create([
            'name' => 'Invited',
            'surname' => 'Staff',
            'role' => 'staff',
            'status' => 'active',
        ]);
        $invitee->assignRole('staff');

        Sanctum::actingAs($admin);

        $this->postJson('/api/panel/calendar/meetings', [
            'title' => 'Genel Koordinasyon Toplantisi',
            'description' => 'Haftalik planlama',
            'location' => 'Toplanti Salonu',
            'start_at' => now()->addDays(2)->toIso8601String(),
            'end_at' => now()->addDays(2)->addHour()->toIso8601String(),
            'assigned_user_ids' => [$invitee->id],
        ])
            ->assertCreated()
            ->assertJsonPath('meeting.event_type', 'meeting')
            ->assertJsonPath('meeting.calendar_event.assigned_user_ids.0', $invitee->id);

        $this->assertDatabaseHas('calendar_events', [
            'event_type' => 'meeting',
            'title' => 'Genel Koordinasyon Toplantisi',
            'project_id' => null,
            'period_id' => null,
        ]);

        Sanctum::actingAs($invitee);

        $this->getJson('/api/panel/calendar/overview')
            ->assertOk()
            ->assertJsonPath('programs.0.event_type', 'meeting')
            ->assertJsonPath('programs.0.title', 'Genel Koordinasyon Toplantisi')
            ->assertJsonPath('summary.total_meetings', 1);

        $this->getJson('/api/panel/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('assigned_tasks.0.event_type', 'meeting')
            ->assertJsonPath('assigned_tasks.0.title', 'Genel Koordinasyon Toplantisi');
    }

    public function test_period_meeting_is_filtered_by_selected_period(): void
    {
        $admin = User::factory()->create([
            'name' => 'Period',
            'surname' => 'Meeting',
            'role' => 'super_admin',
        ]);
        $admin->assignRole('super_admin');
        Sanctum::actingAs($admin);

        $project = Project::query()->create([
            'name' => 'Toplanti Projesi',
            'slug' => 'toplanti-projesi',
            'type' => 'other',
            'status' => 'active',
        ]);
        $activePeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Toplanti Donemi',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $completedPeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Gecmis Toplanti Donemi',
            'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->subMonths(2)->toDateString(),
            'status' => 'completed',
        ]);

        $this->postJson('/api/panel/calendar/meetings', [
            'project_id' => $project->id,
            'period_id' => $activePeriod->id,
            'title' => 'Donemli Toplanti',
            'location' => 'Toplanti Salonu',
            'start_at' => now()->addDays(2)->toIso8601String(),
            'end_at' => now()->addDays(2)->addHour()->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('meeting.period.id', $activePeriod->id);

        $this->getJson("/api/panel/calendar/overview?project_id={$project->id}&period_id={$activePeriod->id}")
            ->assertOk()
            ->assertJsonPath('programs.0.event_type', 'meeting')
            ->assertJsonPath('programs.0.title', 'Donemli Toplanti')
            ->assertJsonPath('programs.0.period.id', $activePeriod->id);

        $this->getJson("/api/panel/calendar/overview?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonCount(0, 'programs')
            ->assertJsonPath('summary.total_meetings', 0);
    }

    public function test_completed_period_blocks_meeting_create_without_archive_permission(): void
    {
        Permission::findOrCreate('calendar.meetings.create', 'web');
        $role = Role::findOrCreate('meeting_creator_without_archive', 'web');
        $role->givePermissionTo('calendar.meetings.create');

        RolePermissionScope::query()->create([
            'role_name' => 'meeting_creator_without_archive',
            'permission_name' => 'calendar.meetings.create',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $creator = User::factory()->create([
            'name' => 'Meeting',
            'surname' => 'Creator',
            'role' => 'coordinator',
        ]);
        $creator->assignRole($role);
        Sanctum::actingAs($creator);

        $project = Project::query()->create([
            'name' => 'Arsiv Toplanti Projesi',
            'slug' => 'arsiv-toplanti-projesi',
            'type' => 'other',
            'status' => 'active',
        ]);
        $completedPeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Arsiv Toplanti Donemi',
            'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->subMonths(2)->toDateString(),
            'status' => 'completed',
        ]);

        $this->postJson('/api/panel/calendar/meetings', [
            'project_id' => $project->id,
            'period_id' => $completedPeriod->id,
            'title' => 'Kapanmis Donem Toplantisi',
            'start_at' => now()->addDays(2)->toIso8601String(),
            'end_at' => now()->addDays(2)->addHour()->toIso8601String(),
        ])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir.');
    }

    public function test_program_index_accepts_completed_period_filter_with_project_context(): void
    {
        Permission::findOrCreate('programs.view', 'web');
        $role = Role::findOrCreate('program_period_viewer', 'web');
        $role->givePermissionTo('programs.view');

        RolePermissionScope::query()->create([
            'role_name' => 'program_period_viewer',
            'permission_name' => 'programs.view',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $viewer = User::factory()->create([
            'name' => 'Program',
            'surname' => 'Viewer',
            'role' => 'coordinator',
        ]);
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);

        $project = Project::query()->create([
            'name' => 'Program Projesi',
            'slug' => 'program-projesi',
            'type' => 'other',
            'status' => 'active',
        ]);
        $otherProject = Project::query()->create([
            'name' => 'Baska Program Projesi',
            'slug' => 'baska-program-projesi',
            'type' => 'other',
            'status' => 'active',
        ]);

        $activePeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Aktif Donem',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $completedPeriod = Period::query()->create([
            'project_id' => $project->id,
            'name' => 'Tamamlanan Donem',
            'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->subMonths(2)->toDateString(),
            'status' => 'completed',
        ]);
        $otherPeriod = Period::query()->create([
            'project_id' => $otherProject->id,
            'name' => 'Baska Donem',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $this->createProgram($project, $activePeriod, 'Aktif Donem Programi');
        $this->createProgram($project, $completedPeriod, 'Gecmis Donem Programi');
        $this->createProgram($otherProject, $otherPeriod, 'Baska Proje Programi');

        $this->getJson("/api/panel/programs?project_id={$project->id}&period_id={$completedPeriod->id}")
            ->assertOk()
            ->assertJsonCount(1, 'programs')
            ->assertJsonPath('programs.0.title', 'Gecmis Donem Programi');

        $this->getJson("/api/panel/programs?project_id={$project->id}&period_id={$otherPeriod->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_id');
    }

    private function createProgram(Project $project, Period $period, string $title): Program
    {
        return Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => $title,
            'location' => 'KADEME',
            'start_at' => now()->addDays(5),
            'end_at' => now()->addDays(5)->addHour(),
            'status' => 'scheduled',
            'radius_meters' => 100,
            'credit_deduction' => 10,
        ]);
    }
}
