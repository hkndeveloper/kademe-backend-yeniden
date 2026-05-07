<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_writes_auth_activity_log(): void
    {
        Role::findOrCreate('student', 'web');

        $user = User::factory()->create([
            'name' => 'Log',
            'surname' => 'User',
            'email' => 'log-user@test.local',
            'password' => Hash::make('password'),
            'role' => 'student',
            'status' => 'active',
            'must_change_password' => false,
        ]);
        $user->assignRole('student');

        $response = $this->postJson('/api/auth/login', [
            'email' => 'log-user@test.local',
            'password' => 'password',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'login',
            'description' => 'auth.login.success',
            'causer_id' => $user->id,
            'subject_id' => $user->id,
        ]);
    }

    public function test_failed_login_writes_auth_activity_log(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'missing@test.local',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'login_failed',
            'description' => 'auth.login.failed',
        ]);
    }
}
