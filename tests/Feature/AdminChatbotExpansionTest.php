<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\Program;
use App\Models\Project;
use App\Models\RolePermissionScope;
use App\Models\Trainer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminChatbotExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_chatbot_returns_trainer_summary_and_multi_format_export(): void
    {
        $this->actingChatbotUser(['chatbot.view' => 'all', 'trainers.view' => 'all']);

        Trainer::query()->create([
            'first_name' => 'Ayse',
            'last_name' => 'Demir',
            'email' => 'ayse.demir@test.local',
            'status' => 'active',
            'kademe_comment' => 'Guclu iletisim.',
        ]);

        $response = $this->postJson('/api/panel/chatbot/query', [
            'message' => 'egitmen ozeti',
        ])->assertOk();

        $response->assertJsonPath('intent', 'trainer_summary');
        $this->assertNotEmpty($response->json('export_token'));

        $export = $this->get('/api/panel/chatbot/export/' . $response->json('export_token') . '?format=docx');
        $export->assertOk();
        $this->assertStringContainsString('.docx', (string) $export->headers->get('content-disposition'));
    }

    public function test_chatbot_project_query_respects_action_scope_and_project_access(): void
    {
        $project = Project::query()->create([
            'name' => 'Diplomasi360',
            'slug' => 'diplomasi360',
            'type' => 'diplomasi360',
            'status' => 'active',
        ]);
        $period = Period::query()->create([
            'project_id' => $project->id,
            'name' => '2026 Bahar',
            'status' => 'active',
            'start_date' => '2026-03-01',
            'end_date' => '2026-06-30',
        ]);
        Program::query()->create([
            'project_id' => $project->id,
            'period_id' => $period->id,
            'title' => 'Diplomasi Atolyesi',
            'status' => 'scheduled',
            'start_at' => now(),
            'end_at' => now()->addHour(),
        ]);

        $this->actingChatbotUser([
            'chatbot.view' => 'all',
            'projects.view' => ['selected_projects', ['project_ids' => [$project->id]]],
            'programs.view' => ['selected_projects', ['project_ids' => [$project->id]]],
        ]);

        $response = $this->postJson('/api/panel/chatbot/query', [
            'message' => 'Diplomasi360 program listesi',
        ])->assertOk();

        $response->assertJsonPath('intent', 'program_list');
        $response->assertJsonPath('table.rows.0.1', 'Diplomasi Atolyesi');
    }

    private function actingChatbotUser(array $permissions): User
    {
        $role = Role::findOrCreate('chatbot_expansion_' . md5(json_encode($permissions)), 'web');
        $role->givePermissionTo(array_keys($permissions));

        foreach ($permissions as $permission => $scope) {
            $scopeType = is_array($scope) ? $scope[0] : $scope;
            $payload = is_array($scope) ? $scope[1] : [];
            RolePermissionScope::query()->create([
                'role_name' => $role->name,
                'permission_name' => $permission,
                'scope_type' => $scopeType,
                'scope_payload' => $payload,
            ]);
        }

        $user = User::factory()->create([
            'role' => 'visitor',
            'surname' => 'Chatbot',
            'email' => uniqid('chatbot-', true) . '@test.local',
        ]);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}