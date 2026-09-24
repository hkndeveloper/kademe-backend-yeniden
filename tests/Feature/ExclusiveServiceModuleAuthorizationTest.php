<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PermissionResolver;
use App\Support\PanelModuleCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExclusiveServiceModuleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config()->set('coordination_authorization.mode', 'enforce');
    }

    public function test_project_positions_have_scoped_financial_access_but_no_purchase_approval_or_other_service_permissions(): void
    {
        $forbiddenPermissions = [
            'financial.approve',
            'financial.reject',
            'financial.mark_paid',
            'announcements.view',
            'content.view',
            'volunteer.view',
            'motivation.view',
            'alumni_opportunities.view',
            'forum.view',
        ];
        $forbiddenModules = [
            'announcements',
            'content',
            'volunteer',
            'motivation',
            'alumni_opportunities_panel',
            'forum_panel',
        ];

        foreach (['coordinator', 'staff'] as $position) {
            for ($project = 1; $project <= 6; $project++) {
                $user = $this->user(sprintf('demo.%s.p%02d@kademe.org', $position, $project));
                $resolver = app(PermissionResolver::class);

                $this->assertTrue($resolver->hasPermission($user, 'inbox.view'));
                $this->assertTrue($resolver->hasPermission($user, 'financial.view'));
                $this->assertTrue($resolver->hasPermission($user, 'financial.create'));
                $this->assertTrue($resolver->hasPermission($user, 'financial.invoice.download'));
                $this->assertSame($position === 'coordinator', $resolver->hasPermission($user, 'financial.export'));
                foreach ($forbiddenPermissions as $permission) {
                    $this->assertFalse($resolver->hasPermission($user, $permission), "{$user->email} unexpectedly has {$permission}.");
                }

                $moduleIds = $this->moduleIds($user);
                $this->assertContains('inbox', $moduleIds);
                $this->assertContains('financials', $moduleIds);
                foreach ($forbiddenModules as $moduleId) {
                    $this->assertNotContains($moduleId, $moduleIds, "{$user->email} unexpectedly sees {$moduleId}.");
                }
            }
        }

        foreach (['demo.coordinator.p01@kademe.org', 'demo.staff.p01@kademe.org'] as $email) {
            Sanctum::actingAs($this->user($email));
            $this->getJson('/api/panel/inbox/messages')->assertOk();
            $this->getJson('/api/panel/financials')->assertOk();
            $this->getJson('/api/panel/content')->assertForbidden();
            $this->getJson('/api/panel/announcements')->assertForbidden();
            $this->getJson('/api/panel/volunteer/opportunities')->assertForbidden();
            $this->getJson('/api/panel/motivation/lists')->assertForbidden();
            $this->getJson('/api/panel/alumni-opportunities')->assertForbidden();
            $this->getJson('/api/panel/forum/posts')->assertForbidden();
        }
    }

    public function test_service_positions_follow_media_purchase_and_community_ownership_boundaries(): void
    {
        $cases = [
            'demo.coordinator.media@kademe.org' => [
                'allow' => ['inbox.view', 'announcements.view', 'content.view', 'alumni_opportunities.view', 'alumni_opportunities.manage'],
                'deny' => ['financial.view', 'volunteer.view', 'motivation.view', 'forum.view'],
            ],
            'demo.staff.media@kademe.org' => [
                'allow' => ['inbox.view', 'announcements.view', 'content.view', 'alumni_opportunities.view'],
                'deny' => ['alumni_opportunities.manage', 'financial.view', 'volunteer.view', 'motivation.view', 'forum.view'],
            ],
            'demo.coordinator.purchase.organization@kademe.org' => [
                'allow' => ['inbox.view', 'financial.view'],
                'deny' => ['announcements.view', 'content.view', 'alumni_opportunities.view', 'volunteer.view', 'motivation.view', 'forum.view'],
            ],
            'demo.staff.purchase.organization@kademe.org' => [
                'allow' => ['inbox.view', 'financial.view'],
                'deny' => ['announcements.view', 'content.view', 'alumni_opportunities.view', 'volunteer.view', 'motivation.view', 'forum.view'],
            ],
            'demo.coordinator.community.culture@kademe.org' => [
                'allow' => ['inbox.view', 'volunteer.view', 'motivation.view', 'projects.participants.view', 'projects.alumni.manage', 'projects.alumni.view', 'projects.student_cv.view', 'certificates.view', 'certificates.create', 'certificates.delete', 'certificates.export', 'alumni_opportunities.view', 'alumni_opportunities.manage'],
                'deny' => ['financial.view', 'announcements.view', 'content.view', 'forum.view', 'projects.participants.manage'],
            ],
            'demo.staff.community.culture@kademe.org' => [
                'allow' => ['inbox.view', 'volunteer.view', 'motivation.view', 'projects.alumni.view', 'projects.student_cv.view', 'certificates.view', 'alumni_opportunities.view'],
                'deny' => ['financial.view', 'announcements.view', 'content.view', 'forum.view', 'projects.participants.manage', 'certificates.create', 'alumni_opportunities.manage'],
            ],
        ];

        foreach ($cases as $email => $expectations) {
            $user = $this->user($email);
            $resolver = app(PermissionResolver::class);

            foreach ($expectations['allow'] as $permission) {
                $this->assertTrue($resolver->hasPermission($user, $permission), "{$email} should have {$permission}.");
            }
            foreach ($expectations['deny'] as $permission) {
                $this->assertFalse($resolver->hasPermission($user, $permission), "{$email} should not have {$permission}.");
            }
        }

        Sanctum::actingAs($this->user('demo.coordinator.media@kademe.org'));
        $this->getJson('/api/panel/content')->assertOk();
        $this->getJson('/api/panel/announcements')->assertOk();
        $this->getJson('/api/panel/alumni-opportunities')->assertOk();
        $this->getJson('/api/panel/financials')->assertForbidden();

        Sanctum::actingAs($this->user('demo.coordinator.purchase.organization@kademe.org'));
        $this->getJson('/api/panel/financials')->assertOk();
        $this->getJson('/api/panel/content')->assertForbidden();
        $this->getJson('/api/panel/announcements')->assertForbidden();

        Sanctum::actingAs($this->user('demo.coordinator.community.culture@kademe.org'));
        $this->getJson('/api/panel/volunteer/opportunities')->assertOk();
        $this->getJson('/api/panel/motivation/lists')->assertOk();
        $this->getJson('/api/panel/participants')->assertOk();
        $this->getJson('/api/panel/certificates')->assertOk();
        $this->getJson('/api/panel/alumni-opportunities')->assertOk();
        $this->getJson('/api/panel/announcements')->assertForbidden();
        $this->getJson('/api/panel/content')->assertForbidden();
    }

    /** @return list<string> */
    private function moduleIds(User $user): array
    {
        return collect(app(PanelModuleCatalog::class)->visibleFor($user)['modules'])
            ->where('panel_type', 'authority')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
