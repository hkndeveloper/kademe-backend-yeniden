<?php

return [
    // Enable only after previewing the intended active projects.
    'auto_schedule_enabled' => (bool) env('APPLICATION_WAITLIST_AUTO_SCHEDULE_ENABLED', false),
    'auto_project_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('APPLICATION_WAITLIST_AUTO_PROJECT_IDS', ''))
    ))),
];
