<?php

/**
 * YF-0 current-state baseline.
 *
 * This fixture started as the YF-0 current-state capture. Each completed phase
 * updates only its intentionally changed surface so later regressions remain
 * machine-readable.
 */
return [
    'inventory_counts' => [
        'routes' => [
            'total' => 592,
            'api_total' => 583,
            'api/panel/' => 274,
            'api/admin/' => 195,
            'api/coordinator/' => 10,
            'api/staff/' => 9,
        ],
        'authority_modules' => 39,
        'authority_actions' => 176,
        'authority_entry_permissions' => 60,
        'granular_permissions' => 196,
    ],
    'project_coordinator_modules' => [
        'applications',
        'calendar',
        'certificates',
        'dashboard',
        'digital_bohca',
        'inbox',
        'members',
        'my_project',
        'participants',
        'periods',
        'profile',
        'programs',
        'requests',
        'support',
    ],
    'project_staff_modules' => [
        'calendar',
        'certificates',
        'dashboard',
        'digital_bohca',
        'inbox',
        'my_project',
        'profile',
        'programs',
        'requests',
        'support',
    ],
    'project_extra_modules_by_type' => [
        'diplomasi360' => ['diplomasi360'],
        'kademe_plus' => ['kademe_plus'],
        'eurodesk' => ['eurodesk'],
        'pergel_fellowship' => ['pergel', 'assignments'],
        'kpd' => ['kpd'],
        'zirve_kademe' => ['zirve_kademe'],
    ],
    'service_modules' => [
        'service_media' => [
            'coordinator' => [
                'announcements', 'content', 'dashboard', 'inbox', 'members',
                'profile', 'programs', 'projects', 'requests', 'support',
                'alumni_opportunities_panel',
            ],
            'staff' => [
                'announcements', 'content', 'dashboard', 'inbox', 'profile',
                'programs', 'projects', 'requests', 'support', 'alumni_opportunities_panel',
            ],
        ],
        'service_purchase_organization' => [
            'coordinator' => [
                'calendar', 'dashboard', 'financials', 'inbox', 'members', 'profile', 'programs', 'requests', 'support',
            ],
            'staff' => [
                'calendar', 'dashboard', 'financials', 'inbox', 'profile', 'programs', 'requests', 'support',
            ],
        ],
        'service_community_culture' => [
            'coordinator' => [
                'calendar', 'dashboard', 'inbox', 'members',
                'motivation', 'profile', 'programs', 'requests', 'support', 'volunteer',
            ],
            'staff' => [
                'calendar', 'dashboard', 'inbox', 'motivation',
                'profile', 'programs', 'requests', 'support', 'volunteer',
            ],
        ],
    ],
    'project_rule_counts_by_type' => [
        'diplomasi360' => ['coordinator' => 71, 'staff' => 24],
        'kademe_plus' => ['coordinator' => 71, 'staff' => 24],
        'eurodesk' => ['coordinator' => 71, 'staff' => 24],
        'pergel_fellowship' => ['coordinator' => 77, 'staff' => 28],
        'kpd' => ['coordinator' => 74, 'staff' => 25],
        'zirve_kademe' => ['coordinator' => 71, 'staff' => 24],
    ],
    'service_rule_counts' => [
        'service_media' => ['coordinator' => 35, 'staff' => 22],
        'service_purchase_organization' => ['coordinator' => 34, 'staff' => 20],
        'service_community_culture' => ['coordinator' => 30, 'staff' => 18],
    ],
    'announcement_view_modules' => [
        'announcements',
    ],
    'forbidden_project_service_permissions' => [
        'financial.create',
        'financial.view',
        'announcements.view',
        'content.view',
        'volunteer.view',
    ],
    'all_project_family_permissions' => [
        'projects.internships.view',
        'projects.mentors.view',
        'projects.eurodesk.view',
        'projects.rewards.view',
        'kpd.appointments.view',
    ],
];
