<?php

namespace App\Support;

use App\Models\CoordinationUnit;
use App\Models\CoordinationUnitMembership;
use App\Models\CoordinationUnitPermissionRule;

final class CoordinationUnitPermissionTemplateCatalog
{
    /**
     * @return list<array{position: string, permission_name: string, scope_source: string, service_domain: ?string}>
     */
    public static function rulesFor(CoordinationUnit $unit): array
    {
        if ($unit->kind === CoordinationUnit::KIND_PROJECT) {
            return self::projectUnitRules($unit);
        }

        return match ($unit->code) {
            'service_media' => self::mediaRules(),
            'service_purchase_organization' => self::purchaseOrganizationRules(),
            'service_community_culture' => self::communityCultureRules(),
            default => [],
        };
    }

    private static function projectUnitRules(CoordinationUnit $unit): array
    {
        $project = $unit->relationLoaded('project') ? $unit->project : $unit->project()->first();
        $coordinatorFamily = $project
            ? ProjectUnitPermissionTemplateCatalog::permissionsFor($project, CoordinationUnitMembership::POSITION_COORDINATOR)
            : [];
        $staffFamily = $project
            ? ProjectUnitPermissionTemplateCatalog::permissionsFor($project, CoordinationUnitMembership::POSITION_STAFF)
            : [];

        $coordinatorLinked = [
            'dashboard.coordinator.view',
            'projects.view', 'projects.export', 'projects.application_form.update',
            'projects.participants.view', 'projects.participants.manage', 'projects.alumni.view',
            'projects.student_cv.view', 'projects.attendance.view', 'projects.attendance.export',
            'periods.view', 'periods.create', 'periods.update', 'periods.activate',
            'periods.closing.start', 'periods.closing.cancel', 'periods.complete', 'periods.reopen',
            'periods.cancel', 'periods.archive.update', 'periods.archive.correct',
            'periods.archive.verify', 'periods.export',
            'programs.view', 'programs.attendance.view', 'programs.attendance.manage',
            'programs.attendance.export', 'programs.create', 'programs.update', 'programs.complete',
            'programs.qr.manage', 'programs.export',
            'calendar.view', 'calendar.export', 'calendar.assignments.manage',
            'calendar.meetings.create', 'calendar.meetings.manage',
            'applications.view', 'applications.intake.view', 'applications.intake.manage',
            'applications.update_status', 'applications.plan_interview',
            'applications.waitlist.manage', 'applications.export',
            'financial.view', 'financial.create', 'financial.export', 'financial.invoice.download',
            'requests.create', 'support.create', 'inbox.view',
            'certificates.view', 'certificates.create', 'certificates.delete', 'certificates.export',
            ...$coordinatorFamily,
        ];
        $coordinatorOwnUnit = [
            'staff.view', 'staff.update', 'staff.documents.upload',
            'staff.leave.approve', 'staff.leave.reject', 'staff.export',
        ];
        $coordinatorOwnRecord = [
            'requests.view', 'requests.update_status', 'requests.upload_response',
            'support.view', 'support.reply', 'support.close',
            'support.update', 'support.reopen',
        ];
        $staffLinked = [
            'dashboard.staff.view', 'projects.view',
            'programs.view', 'programs.attendance.view', 'programs.attendance.manage',
            'programs.attendance.export', 'programs.create', 'programs.update',
            'programs.qr.manage', 'programs.export',
            'calendar.view',
            'financial.view', 'financial.create', 'financial.invoice.download',
            'requests.create', 'support.create', 'inbox.view',
            'certificates.view',
            ...$staffFamily,
        ];
        $staffOwnRecord = [
            'requests.view', 'requests.update_status', 'requests.upload_response',
            'support.view', 'support.reply',
        ];
        $staffSelf = ['staff.leave.request'];
        $coordinatorSelf = ['staff.leave.request'];

        return [
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT, $coordinatorLinked),
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_OWN_UNIT, $coordinatorOwnUnit),
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_OWN_RECORD, $coordinatorOwnRecord),
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_SELF, $coordinatorSelf),
            ...self::rules(CoordinationUnitMembership::POSITION_STAFF, CoordinationUnitPermissionRule::SCOPE_LINKED_PROJECT, $staffLinked),
            ...self::rules(CoordinationUnitMembership::POSITION_STAFF, CoordinationUnitPermissionRule::SCOPE_OWN_RECORD, $staffOwnRecord),
            ...self::rules(CoordinationUnitMembership::POSITION_STAFF, CoordinationUnitPermissionRule::SCOPE_SELF, $staffSelf),
        ];
    }

    private static function mediaRules(): array
    {
        $coordinator = [
            'dashboard.coordinator.view',
            'projects.public_content.view', 'projects.public_content.update', 'projects.gallery.update',
            'programs.view', 'programs.media.upload',
            'announcements.view', 'announcements.create', 'announcements.update',
            'announcements.delete', 'announcements.export',
            'announcements.send_sms', 'announcements.send_email',
            'inbox.view', 'alumni_opportunities.view', 'alumni_opportunities.manage',
            'content.view', 'content.blog.create', 'content.blog.update',
            'content.blog.publish', 'content.blog.delete', 'content.blog.export',
            'requests.create', 'support.create',
        ];
        $staff = [
            'dashboard.staff.view',
            'projects.public_content.view', 'projects.public_content.update', 'projects.gallery.update',
            'programs.view', 'programs.media.upload',
            'announcements.view', 'announcements.create', 'announcements.update',
            'inbox.view', 'alumni_opportunities.view',
            'content.view', 'content.blog.create', 'content.blog.update',
            'requests.create', 'support.create',
        ];

        return [
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS, $coordinator, 'media'),
            ...self::rules(CoordinationUnitMembership::POSITION_STAFF, CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS, $staff, 'media'),
            ...self::servicePeopleRules(),
        ];
    }

    private static function purchaseOrganizationRules(): array
    {
        $coordinatorFinance = [
            'dashboard.coordinator.view',
            'financial.view', 'financial.create', 'financial.update', 'financial.delete',
            'financial.approve', 'financial.reject', 'financial.mark_paid',
            'financial.export', 'financial.invoice.download',
        ];
        $staffFinance = [
            'dashboard.staff.view', 'financial.view', 'financial.create',
            'financial.update', 'financial.export', 'financial.invoice.download',
        ];
        $coordinatorOrganization = [
            'requests.view', 'requests.create', 'requests.update_status',
            'requests.upload_response', 'requests.export',
            'support.view', 'support.create', 'support.assign', 'support.reply',
            'support.close', 'support.update', 'support.reopen', 'support.export',
            'inbox.view',
            'calendar.view', 'calendar.export', 'calendar.meetings.create', 'calendar.meetings.manage',
            'programs.logistics.view', 'programs.logistics.update',
        ];
        $staffOrganization = [
            'requests.view', 'requests.create', 'requests.update_status',
            'requests.upload_response', 'requests.export',
            'support.view', 'support.create', 'support.reply', 'support.close',
            'inbox.view',
            'calendar.view',
            'programs.logistics.view', 'programs.logistics.update',
        ];

        return [
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS, $coordinatorFinance, 'finance_procurement'),
            ...self::rules(CoordinationUnitMembership::POSITION_STAFF, CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS, $staffFinance, 'finance_procurement'),
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS, $coordinatorOrganization, 'organization'),
            ...self::rules(CoordinationUnitMembership::POSITION_STAFF, CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS, $staffOrganization, 'organization'),
            ...self::servicePeopleRules(false),
        ];
    }

    private static function communityCultureRules(): array
    {
        $coordinatorAlumni = [
            'projects.participants.view', 'projects.alumni.manage',
            'projects.alumni.view', 'projects.student_cv.view',
            'certificates.view', 'certificates.create', 'certificates.delete', 'certificates.export',
            'alumni_opportunities.view', 'alumni_opportunities.manage',
        ];
        $staffAlumni = [
            'projects.alumni.view', 'projects.student_cv.view',
            'certificates.view', 'alumni_opportunities.view',
        ];
        $coordinator = [
            'dashboard.coordinator.view',
            'programs.community_event.view', 'programs.community_event.create',
            'programs.community_event.update', 'programs.community_event.attendance.view',
            'programs.community_event.attendance.manage', 'programs.community_event.attendance.export',
            'programs.logistics.update',
            'calendar.view', 'calendar.export', 'calendar.meetings.create', 'calendar.meetings.manage',
            'volunteer.view', 'volunteer.manage', 'inbox.view',
            'requests.create', 'support.create',
            ...$coordinatorAlumni,
        ];
        $staff = [
            'dashboard.staff.view',
            'programs.community_event.view', 'programs.community_event.attendance.view',
            'programs.community_event.attendance.manage', 'programs.logistics.update',
            'calendar.view',
            'calendar.meetings.create', 'volunteer.view', 'inbox.view',
            'requests.create', 'support.create',
            ...$staffAlumni,
        ];

        return [
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS, $coordinator, 'community_culture'),
            ...self::rules(CoordinationUnitMembership::POSITION_STAFF, CoordinationUnitPermissionRule::SCOPE_RESPONSIBILITY_PROJECTS, $staff, 'community_culture'),
            ...self::rules(CoordinationUnitMembership::POSITION_COORDINATOR, CoordinationUnitPermissionRule::SCOPE_ALL, ['motivation.view', 'motivation.manage'], 'community_culture'),
            ...self::rules(CoordinationUnitMembership::POSITION_STAFF, CoordinationUnitPermissionRule::SCOPE_ALL, ['motivation.view'], 'community_culture'),
            ...self::servicePeopleRules(),
        ];
    }

    private static function servicePeopleRules(bool $includeWorkflowRules = true): array
    {
        $rules = [
            ...self::rules(
                CoordinationUnitMembership::POSITION_COORDINATOR,
                CoordinationUnitPermissionRule::SCOPE_OWN_UNIT,
                ['staff.view', 'staff.leave.approve', 'staff.leave.reject']
            ),
            ...self::rules(
                CoordinationUnitMembership::POSITION_COORDINATOR,
                CoordinationUnitPermissionRule::SCOPE_SELF,
                ['staff.leave.request']
            ),
            ...self::rules(
                CoordinationUnitMembership::POSITION_STAFF,
                CoordinationUnitPermissionRule::SCOPE_SELF,
                ['staff.leave.request']
            ),
        ];

        if (! $includeWorkflowRules) {
            return $rules;
        }

        return [
            ...$rules,
            ...self::rules(
                CoordinationUnitMembership::POSITION_COORDINATOR,
                CoordinationUnitPermissionRule::SCOPE_OWN_RECORD,
                [
                    'requests.view', 'requests.update_status', 'requests.upload_response',
                    'support.view', 'support.reply', 'support.update', 'support.reopen',
                ]
            ),
            ...self::rules(
                CoordinationUnitMembership::POSITION_STAFF,
                CoordinationUnitPermissionRule::SCOPE_OWN_RECORD,
                [
                    'requests.view', 'requests.update_status', 'requests.upload_response',
                    'support.view', 'support.reply',
                ]
            ),
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @return list<array{position: string, permission_name: string, scope_source: string, service_domain: ?string}>
     */
    private static function rules(string $position, string $scopeSource, array $permissions, ?string $serviceDomain = null): array
    {
        return collect($permissions)
            ->unique()
            ->map(fn (string $permission) => [
                'position' => $position,
                'permission_name' => $permission,
                'scope_source' => $scopeSource,
                'service_domain' => $serviceDomain,
            ])
            ->values()
            ->all();
    }
}
