<?php

namespace Database\Seeders;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Participant;
use App\Models\Program;
use App\Models\Project;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Support\CoordinationUnitCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class DemoProjectRoleDataSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'Demo1234!';

    public function run(
        CoordinationUnitBackfillService $backfillService,
        CoordinationUnitPermissionRuleSyncService $permissionRuleSyncService
    ): void {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoProjectRoleDataSeeder yalniz local/testing ortaminda calistirilabilir.');
        }

        foreach (['super_admin', 'coordinator', 'staff', 'student', 'alumni'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        $superAdmin = $this->upsertUser(
            email: 'demo.superadmin@kademe.org',
            name: 'Demo',
            surname: 'Super Admin',
            role: 'super_admin',
            phone: '05550000001',
            tcNo: '90000000001'
        );

        $projects = Project::query()->with('activePeriods')->where('status', 'active')->orderBy('id')->get();
        if ($projects->isEmpty()) {
            throw new RuntimeException('Demo birim verisi icin en az bir aktif proje bulunmalidir.');
        }

        $this->prepareCoordinationFoundation($backfillService, $permissionRuleSyncService);

        foreach ($projects as $project) {
            $this->seedProjectUsers($project, $superAdmin);
        }

        $this->attachLegacyExampleCoordinator($projects->firstOrFail(), $superAdmin);
        $this->seedServiceUnitUsers($superAdmin);
        $this->seedContextSwitchMemberships($projects->firstOrFail(), $superAdmin);
        $this->seedDemoPrograms($projects);
    }

    private function prepareCoordinationFoundation(
        CoordinationUnitBackfillService $backfillService,
        CoordinationUnitPermissionRuleSyncService $permissionRuleSyncService
    ): void {
        $backfill = $backfillService->execute(true);
        if (($backfill['summary']['blocker_count'] ?? 0) > 0
            || ! ($backfill['verification']['healthy'] ?? false)
            || ! ($backfill['verification']['idempotent'] ?? false)) {
            throw new RuntimeException('Demo koordinasyon birimleri guvenli bicimde hazirlanamadi.');
        }

        $permissionSync = $permissionRuleSyncService->execute(true);
        if (($permissionSync['summary']['blocker_count'] ?? 0) > 0
            || ! ($permissionSync['verification']['healthy'] ?? false)
            || ! ($permissionSync['verification']['idempotent'] ?? false)) {
            throw new RuntimeException('Demo koordinasyon yetki kurallari guvenli bicimde hazirlanamadi.');
        }
    }

    private function seedProjectUsers(Project $project, User $superAdmin): void
    {
        $suffix = str_pad((string) $project->id, 2, '0', STR_PAD_LEFT);
        $unit = CoordinationUnit::query()
            ->where('code', CoordinationUnitCatalog::projectUnitCode((int) $project->id))
            ->firstOrFail();

        $coordinator = $this->upsertUser(
            email: "demo.coordinator.p{$suffix}@kademe.org",
            name: 'Demo',
            surname: "Coordinator P{$suffix}",
            role: 'coordinator',
            phone: sprintf('05551%06d', $project->id),
            tcNo: sprintf('91000%06d', $project->id)
        );
        $project->coordinators()->syncWithoutDetaching([$coordinator->id]);
        $this->upsertStaffProfile($coordinator, 'coordinator', $unit->name, 3);
        $this->upsertMembership($unit, $coordinator, CoordinationUnitMembership::POSITION_COORDINATOR, $superAdmin);

        $staff = $this->upsertUser(
            email: "demo.staff.p{$suffix}@kademe.org",
            name: 'Demo',
            surname: "Staff P{$suffix}",
            role: 'staff',
            phone: sprintf('05552%06d', $project->id),
            tcNo: sprintf('92000%06d', $project->id)
        );
        $project->assignedStaff()->syncWithoutDetaching([$staff->id]);
        $this->upsertStaffProfile($staff, 'specialist', $unit->name, 2);
        $this->upsertMembership($unit, $staff, CoordinationUnitMembership::POSITION_STAFF, $superAdmin);

        $student = $this->upsertUser(
            email: "demo.student.p{$suffix}@kademe.org",
            name: 'Demo',
            surname: "Student P{$suffix}",
            role: 'student',
            phone: sprintf('05553%06d', $project->id),
            tcNo: sprintf('93000%06d', $project->id),
            extra: [
                'university' => 'Demo Universitesi',
                'department' => 'Bilgisayar Muhendisligi',
                'class_year' => '3',
            ]
        );

        $alumni = $this->upsertUser(
            email: "demo.alumni.p{$suffix}@kademe.org",
            name: 'Demo',
            surname: "Alumni P{$suffix}",
            role: 'alumni',
            phone: sprintf('05554%06d', $project->id),
            tcNo: sprintf('94000%06d', $project->id),
            extra: [
                'university' => 'Demo Universitesi',
                'department' => 'Isletme',
                'class_year' => 'mezun',
            ]
        );

        $activePeriod = $project->activePeriods->first();
        if (! $activePeriod) {
            return;
        }

        Participant::updateOrCreate(
            [
                'user_id' => $student->id,
                'project_id' => $project->id,
                'period_id' => $activePeriod->id,
            ],
            [
                'status' => 'active',
                'credit' => 100,
                'enrolled_at' => now()->subMonth(),
            ]
        );

        Participant::updateOrCreate(
            [
                'user_id' => $alumni->id,
                'project_id' => $project->id,
                'period_id' => $activePeriod->id,
            ],
            [
                'status' => 'graduated',
                'graduation_status' => 'graduated',
                'credit' => 120,
                'enrolled_at' => now()->subMonths(4),
                'graduated_at' => now()->subWeek(),
            ]
        );
    }

    private function seedServiceUnitUsers(User $superAdmin): void
    {
        $serviceAccounts = [
            'service_media' => ['slug' => 'media', 'sequence' => 1],
            'service_purchase_organization' => ['slug' => 'purchase.organization', 'sequence' => 2],
            'service_community_culture' => ['slug' => 'community.culture', 'sequence' => 3],
        ];

        foreach ($serviceAccounts as $unitCode => $account) {
            $unit = CoordinationUnit::query()->where('code', $unitCode)->firstOrFail();
            $sequence = $account['sequence'];
            $slug = $account['slug'];

            $coordinator = $this->upsertUser(
                email: "demo.coordinator.{$slug}@kademe.org",
                name: 'Demo',
                surname: $unit->name.' Coordinator',
                role: 'coordinator',
                phone: sprintf('05556%06d', $sequence),
                tcNo: sprintf('95000%06d', $sequence)
            );
            $this->upsertStaffProfile($coordinator, 'coordinator', $unit->name, 3);
            $this->upsertMembership($unit, $coordinator, CoordinationUnitMembership::POSITION_COORDINATOR, $superAdmin);

            $staff = $this->upsertUser(
                email: "demo.staff.{$slug}@kademe.org",
                name: 'Demo',
                surname: $unit->name.' Staff',
                role: 'staff',
                phone: sprintf('05557%06d', $sequence),
                tcNo: sprintf('96000%06d', $sequence)
            );
            $this->upsertStaffProfile($staff, 'specialist', $unit->name, 2);
            $this->upsertMembership($unit, $staff, CoordinationUnitMembership::POSITION_STAFF, $superAdmin);
        }
    }

    private function attachLegacyExampleCoordinator(Project $project, User $superAdmin): void
    {
        $coordinator = User::query()->where('email', 'koordinator@kademe.org')->first();
        if (! $coordinator || $coordinator->role !== 'coordinator') {
            return;
        }

        $unit = CoordinationUnit::query()
            ->where('code', CoordinationUnitCatalog::projectUnitCode((int) $project->id))
            ->firstOrFail();
        $project->coordinators()->syncWithoutDetaching([$coordinator->id]);
        $this->upsertStaffProfile($coordinator, 'coordinator', $unit->name, 3);
        $this->upsertMembership($unit, $coordinator, CoordinationUnitMembership::POSITION_COORDINATOR, $superAdmin);
    }

    private function seedContextSwitchMemberships(Project $firstProject, User $superAdmin): void
    {
        $purchaseUnit = CoordinationUnit::query()
            ->where('code', 'service_purchase_organization')
            ->firstOrFail();
        $mediaCoordinator = User::query()
            ->where('email', 'demo.coordinator.media@kademe.org')
            ->firstOrFail();
        $this->upsertMembership(
            $purchaseUnit,
            $mediaCoordinator,
            CoordinationUnitMembership::POSITION_STAFF,
            $superAdmin,
            false
        );

        $communityUnit = CoordinationUnit::query()
            ->where('code', 'service_community_culture')
            ->firstOrFail();
        $suffix = str_pad((string) $firstProject->id, 2, '0', STR_PAD_LEFT);
        $projectStaff = User::query()
            ->where('email', "demo.staff.p{$suffix}@kademe.org")
            ->firstOrFail();
        $this->upsertMembership(
            $communityUnit,
            $projectStaff,
            CoordinationUnitMembership::POSITION_STAFF,
            $superAdmin,
            false
        );
    }

    private function seedDemoPrograms(iterable $projects): void
    {
        $communityUnit = CoordinationUnit::query()
            ->where('code', 'service_community_culture')
            ->firstOrFail();
        $communityCoordinator = User::query()
            ->where('email', 'demo.coordinator.community.culture@kademe.org')
            ->firstOrFail();

        foreach ($projects as $project) {
            $suffix = str_pad((string) $project->id, 2, '0', STR_PAD_LEFT);
            $projectStaff = User::query()->where('email', "demo.staff.p{$suffix}@kademe.org")->firstOrFail();
            $periodId = $project->activePeriods->first()?->id;

            $this->upsertProgram(
                project: $project,
                title: 'Demo Proje Programi',
                kind: Program::KIND_CORE_PROGRAM,
                managingUnitId: null,
                createdBy: $projectStaff,
                periodId: $periodId
            );
            $this->upsertProgram(
                project: $project,
                title: 'Demo Topluluk Etkinligi',
                kind: Program::KIND_COMMUNITY_EVENT,
                managingUnitId: $communityUnit->id,
                createdBy: $communityCoordinator,
                periodId: $periodId
            );
        }
    }

    private function upsertProgram(
        Project $project,
        string $title,
        string $kind,
        ?int $managingUnitId,
        User $createdBy,
        ?int $periodId
    ): void {
        Program::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'program_kind' => $kind,
                'title' => $title,
            ],
            [
                'period_id' => $periodId,
                'managing_unit_id' => $managingUnitId,
                'description' => 'Birim yetki ve yoklama kabul testi icin yerel demo kaydi.',
                'location' => 'KADEME Demo Salonu',
                'start_at' => now()->subHour(),
                'end_at' => now()->addHour(),
                'credit_deduction' => 10,
                'target_audience' => ['student', 'alumni'],
                'status' => 'active',
                'is_public' => false,
                'is_featured' => false,
                'created_by' => $createdBy->id,
            ]
        );
    }

    private function upsertUser(
        string $email,
        string $name,
        string $surname,
        string $role,
        string $phone,
        string $tcNo,
        array $extra = []
    ): User {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'surname' => $surname,
                'password' => Hash::make(self::DEMO_PASSWORD),
                'role' => $role,
                'status' => 'active',
                'phone' => $phone,
                'tc_no' => $tcNo,
                'email_verified_at' => now(),
                ...$extra,
            ]
        );
        $user->syncRoles([$role]);

        if ($user->kvkk_consent_at === null) {
            $user->forceFill(['kvkk_consent_at' => now()])->save();
        }

        return $user;
    }

    private function upsertStaffProfile(User $user, string $title, string $unit, int $startedMonthsAgo): void
    {
        StaffProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'title' => $title,
                'unit' => $unit,
                'contract_type' => 'full_time',
                'start_date' => now()->subMonths($startedMonthsAgo)->toDateString(),
            ]
        );
    }

    private function upsertMembership(
        CoordinationUnit $unit,
        User $user,
        string $position,
        User $superAdmin,
        bool $isPrimary = true
    ): void {
        CoordinationUnitMembership::query()->updateOrCreate(
            [
                'unit_id' => $unit->id,
                'user_id' => $user->id,
            ],
            [
                'position' => $position,
                'is_primary' => $isPrimary,
                'status' => CoordinationUnitMembership::STATUS_ACTIVE,
                'starts_at' => null,
                'ends_at' => null,
                'assigned_by' => $superAdmin->id,
            ]
        );
    }
}
