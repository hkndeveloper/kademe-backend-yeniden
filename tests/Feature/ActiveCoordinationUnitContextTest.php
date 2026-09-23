<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitMembershipPermissionOverride;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActiveCoordinationUnitContextTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CoordinationUnit $media;

    private CoordinationUnit $purchase;

    private CoordinationUnitMembership $mediaMembership;

    private CoordinationUnitMembership $purchaseMembership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Project::query()->create([
            'name' => 'Context Project',
            'slug' => 'context-project',
            'type' => 'other',
            'status' => 'active',
        ]);
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        $this->media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();
        $this->purchase = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        $this->user = User::factory()->create([
            'surname' => 'Context',
            'role' => 'staff',
            'status' => 'active',
        ]);
        $this->user->assignRole('staff');
        $this->mediaMembership = $this->membership($this->media, true);
        $this->purchaseMembership = $this->membership($this->purchase, false);

        config()->set('coordination_authorization.mode', 'enforce');
        config()->set('coordination_authorization.active_unit_fallback', 'primary');
        Sanctum::actingAs($this->user);
    }

    public function test_header_selects_one_membership_for_manifest_and_endpoint_authorization(): void
    {
        $mediaHeaders = ['X-Coordination-Unit-Id' => (string) $this->media->id];
        $mediaModules = $this->withHeaders($mediaHeaders)
            ->getJson('/api/panel/modules')
            ->assertOk()
            ->assertHeader('X-Coordination-Unit-Id', (string) $this->media->id)
            ->assertJsonPath('authorization_context.active_unit_id', $this->media->id)
            ->json('modules');

        $this->assertContains('content', collect($mediaModules)->pluck('id')->all());
        $this->assertNotContains('financials', collect($mediaModules)->pluck('id')->all());
        $this->withHeaders($mediaHeaders)->getJson('/api/panel/content')->assertOk();
        $this->withHeaders($mediaHeaders)->getJson('/api/panel/financials')->assertForbidden();

        $purchaseHeaders = ['X-Coordination-Unit-Id' => (string) $this->purchase->id];
        $purchaseModules = $this->withHeaders($purchaseHeaders)
            ->getJson('/api/panel/modules')
            ->assertOk()
            ->assertJsonPath('authorization_context.active_unit_id', $this->purchase->id)
            ->json('modules');

        $this->assertContains('financials', collect($purchaseModules)->pluck('id')->all());
        $this->assertNotContains('content', collect($purchaseModules)->pluck('id')->all());
        $this->withHeaders($purchaseHeaders)->getJson('/api/panel/financials')->assertOk();
        $this->withHeaders($purchaseHeaders)->getJson('/api/panel/content')->assertForbidden();
    }

    public function test_missing_header_uses_primary_membership_and_reports_the_fallback(): void
    {
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.organization_context.schema_version', 3)
            ->assertJsonPath('user.organization_context.active_unit_id', $this->media->id)
            ->assertJsonPath('user.organization_context.active_context_source', 'primary_fallback')
            ->assertJsonPath('user.organization_context.selection_required', true);
    }

    public function test_invalid_or_unowned_header_fails_closed(): void
    {
        $this->withHeaders(['X-Coordination-Unit-Id' => 'invalid'])
            ->getJson('/api/panel/modules')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'invalid_coordination_unit_context');

        $this->withHeaders(['X-Coordination-Unit-Id' => '999999'])
            ->getJson('/api/panel/modules')
            ->assertForbidden()
            ->assertJsonPath('error', 'invalid_coordination_unit_context');
    }

    public function test_multiple_memberships_without_primary_require_explicit_context_when_fallback_is_disabled(): void
    {
        CoordinationUnitMembership::query()
            ->where('user_id', $this->user->id)
            ->update(['is_primary' => false]);
        config()->set('coordination_authorization.active_unit_fallback', 'none');

        $this->getJson('/api/panel/modules')
            ->assertStatus(409)
            ->assertJsonPath('error', 'coordination_unit_context_required')
            ->assertJsonPath('context_header', 'X-Coordination-Unit-Id');
    }

    public function test_membership_override_applies_only_in_its_selected_unit(): void
    {
        CoordinationUnitMembershipPermissionOverride::query()->create([
            'membership_id' => $this->mediaMembership->id,
            'permission_name' => 'financial.view',
            'effect' => 'allow',
            'scope_type' => 'selected_projects',
            'scope_payload' => ['project_ids' => Project::query()->pluck('id')->all()],
            'status' => 'active',
        ]);

        $this->withHeaders(['X-Coordination-Unit-Id' => (string) $this->media->id])
            ->getJson('/api/panel/financials')
            ->assertOk();

        CoordinationUnitMembershipPermissionOverride::query()
            ->where('membership_id', $this->mediaMembership->id)
            ->update(['status' => 'passive']);

        $this->withHeaders(['X-Coordination-Unit-Id' => (string) $this->media->id])
            ->getJson('/api/panel/financials')
            ->assertForbidden();
        $this->withHeaders(['X-Coordination-Unit-Id' => (string) $this->purchase->id])
            ->getJson('/api/panel/financials')
            ->assertOk();
    }

    public function test_legacy_global_business_allow_is_ignored_and_reported_without_mutation(): void
    {
        UserPermissionOverride::query()->create([
            'user_id' => $this->user->id,
            'permission_name' => 'financial.view',
            'effect' => 'allow',
            'scope_type' => 'all',
            'scope_payload' => [],
        ]);

        $this->withHeaders(['X-Coordination-Unit-Id' => (string) $this->media->id])
            ->getJson('/api/panel/financials')
            ->assertForbidden();

        $before = UserPermissionOverride::query()->count();
        $this->artisan('coordination-units:audit-global-overrides', ['--json' => true])
            ->expectsOutputToContain('legacy_allow_ignored_until_membership_assignment')
            ->assertSuccessful();
        $this->assertSame($before, UserPermissionOverride::query()->count());
    }

    public function test_audited_panel_request_records_the_acting_unit_and_membership(): void
    {
        $this->withHeaders(['X-Coordination-Unit-Id' => (string) $this->media->id])
            ->getJson('/api/panel/modules')
            ->assertOk();

        $activity = Activity::query()
            ->where('causer_id', $this->user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($this->media->id, (int) $activity->properties->get('acting_unit_id'));
        $this->assertSame($this->mediaMembership->id, (int) $activity->properties->get('acting_membership_id'));
    }

    private function membership(CoordinationUnit $unit, bool $primary): CoordinationUnitMembership
    {
        return CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $this->user->id,
            'position' => CoordinationUnitMembership::POSITION_STAFF,
            'is_primary' => $primary,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);
    }
}
