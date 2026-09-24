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
            'total' => 594,
            'api_total' => 585,
            'api/panel/' => 275,
            'api/admin/' => 196,
            'api/coordinator/' => 10,
            'api/staff/' => 9,
        ],
        'authority_modules' => 39,
        'authority_actions' => 177,
        'authority_entry_permissions' => 60,
        'granular_permissions' => 197,
    ],
    'project_coordinator_modules' => [
        'applications',
        'calendar',
        'certificates',
        'dashboard',
        'digital_bohca',
        'financials',
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
        'financials',
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
                'alumni_opportunities_panel', 'calendar', 'certificates', 'dashboard', 'inbox', 'members',
                'motivation', 'participants', 'profile', 'programs', 'requests', 'support', 'volunteer',
            ],
            'staff' => [
                'alumni_opportunities_panel', 'calendar', 'certificates', 'dashboard', 'inbox', 'motivation',
                'participants', 'profile', 'programs', 'requests', 'support', 'volunteer',
            ],
        ],
    ],
    'project_rule_counts_by_type' => [
        'diplomasi360' => ['coordinator' => 75, 'staff' => 27],
        'kademe_plus' => ['coordinator' => 75, 'staff' => 27],
        'eurodesk' => ['coordinator' => 75, 'staff' => 27],
        'pergel_fellowship' => ['coordinator' => 81, 'staff' => 31],
        'kpd' => ['coordinator' => 78, 'staff' => 28],
        'zirve_kademe' => ['coordinator' => 75, 'staff' => 27],
    ],
    'service_rule_counts' => [
        'service_media' => ['coordinator' => 35, 'staff' => 22],
        'service_purchase_organization' => ['coordinator' => 34, 'staff' => 20],
        'service_community_culture' => ['coordinator' => 40, 'staff' => 22],
    ],
    'announcement_view_modules' => [
        'announcements',
    ],
    'forbidden_project_service_permissions' => [
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
