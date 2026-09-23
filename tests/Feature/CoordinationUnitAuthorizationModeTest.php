<?php

namespace Tests\Feature;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\Project;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\CoordinationUnitBackfillService;
use App\Services\CoordinationUnitPermissionRuleSyncService;
use App\Services\PermissionResolver;
use App\Support\CoordinationUnitCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CoordinationUnitAuthorizationModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function project(string $name): Project
    {
        return Project::query()->create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'type' => 'other',
            'status' => 'active',
        ]);
    }

    private function prepareUnits(): array
    {
        $first = $this->project('First Authorization Project');
        $second = $this->project('Second Authorization Project');
        app(CoordinationUnitBackfillService::class)->execute(true);
        app(CoordinationUnitPermissionRuleSyncService::class)->execute(true);

        return [$first, $second];
    }

    private function authority(string $role = 'coordinator'): User
    {
        $user = User::factory()->create([
            'surname' => 'Authority',
            'role' => $role,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function addMembership(User $user, CoordinationUnit $unit, string $position, bool $primary = true): void
    {
        CoordinationUnitMembership::query()->create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'position' => $position,
            'is_primary' => $primary,
            'status' => CoordinationUnitMembership::STATUS_ACTIVE,
        ]);
        $user->unsetRelation('coordinationUnitMemberships');
    }

    public function test_legacy_mode_remains_the_default_and_ignores_unit_rules_for_authority(): void
    {
        [$first] = $this->prepareUnits();
        $user = $this->authority();
        $purchase = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        $this->addMembership($user, $purchase, CoordinationUnitMembership::POSITION_COORDINATOR);

        config()->set('coordination_authorization.mode', 'legacy');
        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->hasPermission($user, 'projects.participants.view'));
        $this->assertFalse($resolver->canAccessProject($user, 'financial.view', $first->id));
        $this->assertArrayNotHasKey('authorization_meta', $resolver->resolve($user));
    }

    public function test_shadow_mode_returns_legacy_result_and_exposes_non_authoritative_unit_diff(): void
    {
        $this->prepareUnits();
        $user = $this->authority();
        $purchase = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        $this->addMembership($user, $purchase, CoordinationUnitMembership::POSITION_COORDINATOR);
        $resolver = app(PermissionResolver::class);

        config()->set('coordination_authorization.mode', 'legacy');
        $legacy = $resolver->resolve($user);

        Log::spy();
        config()->set('coordination_authorization.mode', 'shadow');
        $shadow = $resolver->resolve($user);

        $this->assertEquals($legacy['effective_permissions'], $shadow['effective_permissions']);
        $this->assertSame('legacy', $shadow['authorization_meta']['authoritative_source']);
        $this->assertTrue($shadow['authorization_meta']['diff']['has_difference']);
        $this->assertSame($purchase->id, $shadow['contexts']['primary_unit_id']);
        $this->assertNotEmpty($shadow['contexts']['unit_project_ids_by_permission']['financial.view']);
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $event, array $context) => $event === 'coordination_authorization.shadow_diff'
                && $context['user_id'] === $user->id
        )->atLeast()->once();
    }

    public function test_enforce_mode_gives_purchase_unit_cross_project_finance_without_participant_access(): void
    {
        [$first, $second] = $this->prepareUnits();
        $user = $this->authority();
        $purchase = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        $this->addMembership($user, $purchase, CoordinationUnitMembership::POSITION_COORDINATOR);

        config()->set('coordination_authorization.mode', 'enforce');
        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->canAccessProject($user, 'financial.view', $first->id));
        $this->assertTrue($resolver->canAccessProject($user, 'financial.view', $second->id));
        $this->assertFalse($resolver->hasPermission($user, 'projects.participants.view'));
        $this->assertFalse($resolver->canAccessProject($user, 'projects.participants.view', $first->id));
        $this->assertSame('coordination_units', $resolver->resolve($user)['authorization_meta']['authoritative_source']);
    }

    public function test_pilot_mode_enforces_only_allowlisted_authority_and_keeps_others_on_legacy(): void
    {
        [$first] = $this->prepareUnits();
        $pilot = $this->authority();
        $nonPilot = $this->authority();
        $purchase = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        $this->addMembership($pilot, $purchase, CoordinationUnitMembership::POSITION_COORDINATOR);

        config()->set('coordination_authorization.mode', 'pilot');
        config()->set('coordination_authorization.pilot_user_ids', [$pilot->id]);
        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->canAccessProject($pilot, 'financial.view', $first->id));
        $this->assertFalse($resolver->hasPermission($pilot, 'projects.participants.view'));
        $this->assertSame('coordination_units', $resolver->resolve($pilot)['authorization_meta']['authoritative_source']);
        $this->assertTrue($resolver->resolve($pilot)['authorization_meta']['pilot_selected']);

        $this->assertTrue($resolver->hasPermission($nonPilot, 'projects.participants.view'));
        $this->assertSame('legacy', $resolver->resolve($nonPilot)['authorization_meta']['authoritative_source']);
        $this->assertFalse($resolver->resolve($nonPilot)['authorization_meta']['pilot_selected']);
        $this->assertTrue($resolver->coordinationUnitsAreAuthoritative($pilot));
        $this->assertFalse($resolver->coordinationUnitsAreAuthoritative($nonPilot));
    }

    public function test_pilot_mode_with_empty_allowlist_fails_safe_to_legacy_for_everyone(): void
    {
        $this->prepareUnits();
        $user = $this->authority();
        config()->set('coordination_authorization.mode', 'pilot');
        config()->set('coordination_authorization.pilot_user_ids', []);

        $resolved = app(PermissionResolver::class)->resolve($user);

        $this->assertSame('legacy', $resolved['authorization_meta']['authoritative_source']);
        $this->assertFalse($resolved['authorization_meta']['pilot_selected']);
        $this->assertFalse(app(PermissionResolver::class)->coordinationUnitsAreAuthoritative($user));
    }

    public function test_primary_membership_is_authoritative_while_union_snapshot_remains_available_for_audit(): void
    {
        [$first, $second] = $this->prepareUnits();
        $user = $this->authority();
        $projectUnit = CoordinationUnit::query()
            ->where('code', CoordinationUnitCatalog::projectUnitCode($first->id))
            ->firstOrFail();
        $media = CoordinationUnit::query()->where('code', 'service_media')->firstOrFail();

        $this->addMembership($user, $projectUnit, CoordinationUnitMembership::POSITION_COORDINATOR, true);
        $this->addMembership($user, $media, CoordinationUnitMembership::POSITION_STAFF, false);

        config()->set('coordination_authorization.mode', 'enforce');
        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->canAccessProject($user, 'applications.view', $first->id));
        $this->assertFalse($resolver->canAccessProject($user, 'applications.view', $second->id));
        $this->assertFalse($resolver->canAccessProject($user, 'programs.media.upload', $first->id));
        $this->assertFalse($resolver->canAccessProject($user, 'programs.media.upload', $second->id));
        $this->assertCount(2, $resolver->resolve($user)['contexts']['unit_memberships']);
        $union = $resolver->resolveCoordinationUnionSnapshot($user);
        $this->assertContains('programs.media.upload', $union['effective_permissions']->all());
        $this->assertContains($first->id, $union['contexts']['project_ids_by_permission']['programs.media.upload']);
        $this->assertContains($second->id, $union['contexts']['project_ids_by_permission']['programs.media.upload']);
    }

    public function test_user_deny_override_wins_over_unit_permission_rule(): void
    {
        [$first] = $this->prepareUnits();
        $user = $this->authority();
        $purchase = CoordinationUnit::query()->where('code', 'service_purchase_organization')->firstOrFail();
        $this->addMembership($user, $purchase, CoordinationUnitMembership::POSITION_COORDINATOR);
        UserPermissionOverride::query()->create([
            'user_id' => $user->id,
            'permission_name' => 'financial.*',
            'effect' => 'deny',
            'scope_payload' => [],
        ]);
        $user->unsetRelation('permissionOverrides');

        config()->set('coordination_authorization.mode', 'enforce');
        $resolver = app(PermissionResolver::class);

        $this->assertFalse($resolver->hasPermission($user, 'financial.view'));
        $this->assertFalse($resolver->canAccessProject($user, 'financial.view', $first->id));
    }

    public function test_enforce_mode_fails_closed_for_authority_without_active_membership(): void
    {
        $this->prepareUnits();
        $user = $this->authority('staff');

        config()->set('coordination_authorization.mode', 'enforce');
        $resolved = app(PermissionResolver::class)->resolve($user);

        $this->assertCount(0, $resolved['effective_permissions']);
        $this->assertSame([], $resolved['contexts']['manageable_project_ids']);
    }

    public function test_own_unit_scope_uses_normalized_unit_identity_and_passive_membership_is_ignored(): void
    {
        [$first] = $this->prepareUnits();
        $coordinator = $this->authority();
        $projectUnit = CoordinationUnit::query()
            ->where('code', CoordinationUnitCatalog::projectUnitCode($first->id))
            ->firstOrFail();
        $this->addMembership(
            $coordinator,
            $projectUnit,
            CoordinationUnitMembership::POSITION_COORDINATOR
        );

        config()->set('coordination_authorization.mode', 'enforce');
        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->canAccessUnit($coordinator, 'staff.view', $projectUnit->name));
        $this->assertFalse($resolver->canAccessUnit($coordinator, 'staff.view', 'Başka Birim'));

        CoordinationUnitMembership::query()
            ->where('user_id', $coordinator->id)
            ->update(['status' => CoordinationUnitMembership::STATUS_PASSIVE]);

        // Permission resolution is intentionally memoized for one HTTP request.
        // A membership mutation becomes authoritative on the next request.
        $resolver->flushRequestCache();

        $this->assertFalse($resolver->hasPermission($coordinator, 'staff.view'));
    }

    public function test_super_admin_remains_legacy_exempt_in_enforce_mode(): void
    {
        [$first] = $this->prepareUnits();
        $admin = $this->authority('super_admin');

        config()->set('coordination_authorization.mode', 'enforce');
        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->canAccessProject($admin, 'financial.view', $first->id));
        $this->assertArrayNotHasKey('authorization_meta', $resolver->resolve($admin));
    }
}
