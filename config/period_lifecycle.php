<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Period scoped data inventory
    |--------------------------------------------------------------------------
    |
    | This inventory is deliberately explicit. A nullable period_id does not
    | have the same meaning in every domain, so audit/backfill code must not
    | infer a period for project-wide or system-wide content.
    |
    */
    'tables' => [
        'participants' => 'required_period_fact',
        'applications' => 'required_period_fact',
        'programs' => 'required_period_fact',
        'assignments' => 'required_period_fact',
        'credit_logs' => 'required_period_fact',
        'waitlist_invitations' => 'required_period_fact',
        'application_windows' => 'required_period_fact',
        'period_archives' => 'archive',
        'period_lifecycle_events' => 'archive',

        'certificates' => 'operational_review',
        'financial_transactions' => 'operational_review',
        'support_tickets' => 'operational_review',
        'requests' => 'operational_review',
        'kpd_appointments' => 'operational_review',
        'kpd_reports' => 'operational_review',
        'participant_mentor' => 'operational_review',

        'application_forms' => 'period_or_project_global',
        'digital_bohca' => 'period_or_project_global',
        'volunteer_opportunities' => 'period_or_project_global',
        'project_modules' => 'period_or_project_global',
        'eurodesk_projects' => 'period_or_project_global',

        'announcements' => 'period_or_system_global',
        'calendar_events' => 'period_or_system_global',
        'forum_posts' => 'period_or_system_global',
    ],

    'required_period_classifications' => [
        'required_period_fact',
        'archive',
    ],

    // Phase 5 pointer backfill/cutover tamamlandiginda true yapilacak.
    'enforce_current_period_pointer' => (bool) env('PERIOD_ENFORCE_CURRENT_POINTER', false),

    // Cutover oncesi kalan status fallback okumalarini loglarda olculebilir kilar.
    'log_legacy_pointer_fallback' => (bool) env('PERIOD_LOG_LEGACY_POINTER_FALLBACK', true),

    'monitoring' => [
        'enabled' => (bool) env('PERIOD_LIFECYCLE_MONITORING_ENABLED', true),
        'archive_verify_schedule_enabled' => (bool) env('PERIOD_ARCHIVE_VERIFY_SCHEDULE_ENABLED', true),
        'archive_verify_time' => env('PERIOD_ARCHIVE_VERIFY_TIME', '03:15'),
        'archive_verify_timezone' => env('PERIOD_ARCHIVE_VERIFY_TIMEZONE', 'Europe/Istanbul'),
        'archive_verify_lock_minutes' => (int) env('PERIOD_ARCHIVE_VERIFY_LOCK_MINUTES', 120),
    ],

    /*
    | Safe defaults until the product-owner decision gate is completed.
    | These values are documentation for Phase 0 and become runtime policy in
    | the closure-readiness phase.
    */
    'closure_defaults' => [
        'open_programs' => 'blocker',
        'open_application_window' => 'blocker',
        'unresolved_applications' => 'blocker',
        'pending_financials' => 'blocker',
        'unreviewed_assignment_submissions' => 'blocker',
        'missing_participant_outcomes' => 'blocker',
        'open_kpd_work' => 'warning',
        'open_support_or_requests' => 'warning',
        'undelivered_certificates' => 'warning',
        'missing_feedback' => 'warning',
        'low_credit' => 'warning',
    ],
];
