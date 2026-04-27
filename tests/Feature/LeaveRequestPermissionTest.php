<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeaveRequestPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_with_permission_can_create_leave_request(): void
    {
        $staff = User::factory()->create([
            'name' => 'Test',
            'surname' => 'Staff',
            'email' => 'staff-leave@test.local',
            'role' => 'staff',
            'status' => 'active',
        ]);
        Role::findOrCreate('staff', 'web');
        $staff->assignRole('staff');

        Sanctum::actingAs($staff);

        $start = now()->addDay()->format('Y-m-d');
        $end = now()->addDays(3)->format('Y-m-d');

        $response = $this->postJson('/api/leave-requests', [
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Test izin',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $staff->id,
            'status' => 'pending',
        ]);
    }

    public function test_student_cannot_create_leave_request(): void
    {
        $student = User::factory()->create([
            'name' => 'Test',
            'surname' => 'Student',
            'email' => 'student-leave@test.local',
            'role' => 'student',
            'status' => 'active',
        ]);
        Role::findOrCreate('student', 'web');
        $student->assignRole('student');

        Sanctum::actingAs($student);

        $response = $this->postJson('/api/leave-requests', [
            'start_date' => now()->addDay()->format('Y-m-d'),
            'end_date' => now()->addDays(2)->format('Y-m-d'),
        ]);

        $response->assertForbidden();
    }
}
