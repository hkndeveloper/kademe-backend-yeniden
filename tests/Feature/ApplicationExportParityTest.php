<?php

namespace Tests\Feature;

use App\Exports\ArrayExport;
use App\Models\Application;
use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicationExportParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 9, 28)->setTime(12, 0, 0));
    }

    private function project(string $name, string $slug): Project
    {
        return Project::query()->create([
            'name' => $name,
            'slug' => $slug,
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function application(Project $project, string $name, string $phone, string $status = 'pending', ?Period $period = null, ?Program $program = null): Application
    {
        $period ??= Period::query()->firstOrCreate(
            ['project_id' => $project->id, 'name' => 'Varsayılan Dönem'],
            [
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'status' => 'active',
            ],
        );
        $user = User::factory()->create([
            'name' => $name,
            'surname' => 'Aday',
            'phone' => $phone,
            'role' => 'student',
        ]);

        return Application::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'period_id' => $period?->id,
            'program_id' => $program?->id,
            'status' => $status,
            'form_data' => [],
        ]);
    }

    private function superAdmin(): void
    {
        $user = User::factory()->create(['surname' => 'Admin', 'role' => 'super_admin']);
        Role::findOrCreate('super_admin', 'web');
        $user->assignRole('super_admin');
        Sanctum::actingAs($user);
    }

    /** @return array{headings: array, rows: array} */
    private function exportedTable(string $path, string $name, string $extension = 'csv'): array
    {
        Excel::fake();
        $this->get($path)->assertOk();
        $table = [];
        Excel::assertDownloaded($name.'_'.now()->format('Ymd_His').'.'.$extension, function (ArrayExport $export) use (&$table) {
            $table = ['headings' => $export->headings(), 'rows' => $export->array()];

            return true;
        });

        return $table;
    }

    public function test_list_and_file_use_same_project_phone_period_and_status_selection(): void
    {
        $this->superAdmin();
        $diplomasi = $this->project('Diplomasi 360', 'diplomasi-360');
        $pergel = $this->project('Pergel', 'pergel');
        $period = Period::query()->create([
            'project_id' => $diplomasi->id,
            'name' => 'Güz Dönemi',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
        $program = Program::query()->create([
            'project_id' => $diplomasi->id,
            'period_id' => $period->id,
            'title' => 'Tanışma Programı',
            'description' => 'Başvuru programı',
            'location' => 'KADEME',
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHour(),
            'status' => 'planned',
            'target_audience' => ['student'],
        ]);
        $target = $this->application($diplomasi, 'Ayşe', '5551112233', 'pending', $period, $program);
        $this->application($diplomasi, 'Fatma', '5559998877', 'accepted', $period);
        $this->application($pergel, 'Zeynep', '5554445566');

        $filters = "project_id={$diplomasi->id}&period_id={$period->id}&status=pending&search=Diplomasi";
        $list = $this->getJson("/api/panel/applications?{$filters}")->assertOk();
        $this->assertSame([$target->id], array_column($list->json('applications.data'), 'id'));

        $table = $this->exportedTable("/api/panel/applications/export?{$filters}&format=csv", 'basvurular');
        $this->assertSame([$target->id], array_column($table['rows'], 0));
        $this->assertSame('Güz Dönemi', $table['rows'][0][2]);
        $this->assertSame('Tanışma Programı', $table['rows'][0][3]);
        $this->assertSame('Değerlendirme bekliyor', $table['rows'][0][8]);
        $this->assertSame('Başvuru Tarihi (İstanbul)', $table['headings'][12]);
        $this->assertSame('28.09.2026 15:00', $table['rows'][0][12]);

        $phoneList = $this->getJson('/api/panel/applications?search=5551112233')->assertOk();
        $phoneTable = $this->exportedTable('/api/panel/applications/export?search=5551112233&format=csv', 'basvurular');
        $this->assertSame([$target->id], array_column($phoneList->json('applications.data'), 'id'));
        $this->assertSame([$target->id], array_column($phoneTable['rows'], 0));
    }

    public function test_export_keeps_project_scope_and_staff_alias_uses_same_search(): void
    {
        $allowed = $this->project('İzinli Proje', 'izinli-proje');
        $outside = $this->project('Diğer Proje', 'diger-proje');
        $target = $this->application($allowed, 'İzinli', '5550001111');
        $this->application($outside, 'Dışarıda', '5552223333');

        $roleName = 'limited_application_exporter';
        $role = Role::findOrCreate($roleName, 'web');
        foreach (['applications.view', 'applications.export'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            RolePermissionScope::query()->updateOrCreate(
                ['role_name' => $roleName, 'permission_name' => $permission],
                ['scope_type' => 'selected_projects', 'scope_payload' => ['project_ids' => [$allowed->id]]],
            );
        }
        $actor = User::factory()->create(['surname' => 'Yetkili', 'role' => 'staff']);
        $actor->assignRole($role);
        Sanctum::actingAs($actor);

        $panelList = $this->getJson('/api/panel/applications?search=Proje')->assertOk();
        $panelTable = $this->exportedTable('/api/panel/applications/export?search=Proje&format=csv', 'basvurular');
        $this->assertSame([$target->id], array_column($panelList->json('applications.data'), 'id'));
        $this->assertSame([$target->id], array_column($panelTable['rows'], 0));

        $staffList = $this->getJson('/api/staff/applications?search=Proje')->assertOk();
        $staffTable = $this->exportedTable('/api/staff/applications/export?search=Proje&format=csv', 'personel_basvurulari');
        $this->assertSame([$target->id], array_column($staffList->json('applications.data'), 'id'));
        $this->assertSame([$target->id], array_column($staffTable['rows'], 0));

        $this->getJson("/api/panel/applications/export?project_id={$outside->id}&format=csv")->assertForbidden();
        $this->getJson("/api/staff/applications/export?project_id={$outside->id}&format=csv")->assertForbidden();
    }

    public function test_all_supported_file_formats_use_the_same_selected_application(): void
    {
        $this->superAdmin();
        $project = $this->project('Biçim Projesi', 'bicim-projesi');
        $target = $this->application($project, 'Belge', '5551231234', 'accepted');
        $this->application($project, 'Diğer', '5558888877', 'rejected');
        $base = "/api/panel/applications/export?project_id={$project->id}&status=accepted";

        $csv = $this->exportedTable($base.'&format=csv', 'basvurular');
        $xlsx = $this->exportedTable($base.'&format=xlsx', 'basvurular', 'xlsx');
        $this->assertSame($csv, $xlsx);
        $this->assertSame([$target->id], array_column($csv['rows'], 0));
        $this->assertSame('Kabul edildi', $csv['rows'][0][8]);

        $filename = 'basvurular_'.now()->format('Ymd_His');
        $this->get($base.'&format=pdf')->assertOk()->assertDownload($filename.'.pdf');
        $this->get($base.'&format=docx')->assertOk()->assertDownload($filename.'.docx');
    }
}
