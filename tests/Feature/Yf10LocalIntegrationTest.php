<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\User;
use App\Services\PermissionResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Yf10LocalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(DatabaseSeeder::class);
        config()->set('coordination_authorization.mode', 'enforce');
        config()->set('coordination_authorization.active_unit_fallback', 'primary');
    }

    public function test_permission_resolver_is_shared_for_one_request_scope(): void
    {
        $this->assertSame(
            app(PermissionResolver::class),
            app(PermissionResolver::class),
        );
    }

    public function test_community_program_list_reuses_one_authorization_snapshot(): void
    {
        $user = User::query()->where('email', 'demo.staff.p01@kademe.org')->firstOrFail();
        $unit = CoordinationUnit::query()->where('code', 'service_community_culture')->firstOrFail();
        Sanctum::actingAs($user, ['*']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this
            ->withHeader('X-Coordination-Unit-Id', (string) $unit->id)
            ->getJson('/api/panel/programs')
            ->assertOk()
            ->assertJsonPath('work_mode', 'community_event');

        $programs = collect($response->json('programs'));
        $this->assertNotEmpty($programs);
        $this->assertTrue($programs->every(
            fn (array $program): bool => ($program['program_kind'] ?? null) === 'community_event'
        ));
        $this->assertLessThan(
            75,
            $queryCount,
            "Program listesi ayni yetki snapshot'ini tekrar sorguluyor: {$queryCount} sorgu."
        );
    }
}
