<?php

namespace Database\Seeders;

use App\Models\Participant;
use App\Models\Project;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DemoProjectRoleDataSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure base roles exist before assignment.
        foreach (['super_admin', 'coordinator', 'staff', 'student', 'alumni'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        // Keep one predictable super admin account for quick access.
        $superAdmin = User::updateOrCreate(
            ['email' => 'demo.superadmin@kademe.org'],
            [
                'name' => 'Demo',
                'surname' => 'Super Admin',
                'password' => Hash::make('Demo1234!'),
                'role' => 'super_admin',
                'status' => 'active',
                'phone' => '05550000001',
                'tc_no' => '90000000001',
                'email_verified_at' => now(),
            ]
        );
        $superAdmin->syncRoles(['super_admin']);

        $projects = Project::query()->with('activePeriods')->orderBy('id')->get();

        foreach ($projects as $project) {
            $suffix = str_pad((string) $project->id, 2, '0', STR_PAD_LEFT);
            $activePeriod = $project->activePeriods->first();

            // 1) Coordinator for this project
            $coordinator = User::updateOrCreate(
                ['email' => "demo.coordinator.p{$suffix}@kademe.org"],
                [
                    'name' => 'Demo',
                    'surname' => "Coordinator P{$suffix}",
                    'password' => Hash::make('Demo1234!'),
                    'role' => 'coordinator',
                    'status' => 'active',
                    'phone' => "0555100{$suffix}",
                    'tc_no' => "9100000{$suffix}",
                    'email_verified_at' => now(),
                ]
            );
            $coordinator->syncRoles(['coordinator']);
            $project->coordinators()->syncWithoutDetaching([$coordinator->id]);

            StaffProfile::updateOrCreate(
                ['user_id' => $coordinator->id],
                [
                    'title' => 'coordinator',
                    'unit' => 'Koordinasyon',
                    'contract_type' => 'full_time',
                    'start_date' => now()->subMonths(3)->toDateString(),
                ]
            );

            // 2) Staff for this project
            $staff = User::updateOrCreate(
                ['email' => "demo.staff.p{$suffix}@kademe.org"],
                [
                    'name' => 'Demo',
                    'surname' => "Staff P{$suffix}",
                    'password' => Hash::make('Demo1234!'),
                    'role' => 'staff',
                    'status' => 'active',
                    'phone' => "0555200{$suffix}",
                    'tc_no' => "9200000{$suffix}",
                    'email_verified_at' => now(),
                ]
            );
            $staff->syncRoles(['staff']);

            StaffProfile::updateOrCreate(
                ['user_id' => $staff->id],
                [
                    'title' => 'specialist',
                    'unit' => 'Operasyon',
                    'contract_type' => 'full_time',
                    'start_date' => now()->subMonths(2)->toDateString(),
                ]
            );

            // 3) Student participant for this project
            $student = User::updateOrCreate(
                ['email' => "demo.student.p{$suffix}@kademe.org"],
                [
                    'name' => 'Demo',
                    'surname' => "Student P{$suffix}",
                    'password' => Hash::make('Demo1234!'),
                    'role' => 'student',
                    'status' => 'active',
                    'phone' => "0555300{$suffix}",
                    'tc_no' => "9300000{$suffix}",
                    'email_verified_at' => now(),
                    'university' => 'Demo Universitesi',
                    'department' => 'Bilgisayar Muhendisligi',
                    'class_year' => '3',
                ]
            );
            $student->syncRoles(['student']);

            // 4) Alumni participant for this project
            $alumni = User::updateOrCreate(
                ['email' => "demo.alumni.p{$suffix}@kademe.org"],
                [
                    'name' => 'Demo',
                    'surname' => "Alumni P{$suffix}",
                    'password' => Hash::make('Demo1234!'),
                    'role' => 'alumni',
                    'status' => 'active',
                    'phone' => "0555400{$suffix}",
                    'tc_no' => "9400000{$suffix}",
                    'email_verified_at' => now(),
                    'university' => 'Demo Universitesi',
                    'department' => 'Isletme',
                    'class_year' => 'mezun',
                ]
            );
            $alumni->syncRoles(['alumni']);

            if ($activePeriod) {
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
        }
    }
}

