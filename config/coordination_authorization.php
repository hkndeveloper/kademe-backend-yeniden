<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Coordination authorization cutover mode
    |--------------------------------------------------------------------------
    |
    | legacy: Current role/pivot/scope result remains authoritative.
    | shadow: Legacy result is returned; unit result is calculated and diffed.
    | pilot: Unit rules are authoritative only for explicitly listed users.
    | enforce: Unit membership rules are authoritative for all authority users.
    |
    */
    'mode' => env('COORDINATION_AUTHORIZATION_MODE', 'legacy'),

    'pilot_user_ids' => collect(explode(',', (string) env('COORDINATION_AUTHORIZATION_PILOT_USER_IDS', '')))
        ->map(fn (string $value) => trim($value))
        ->filter(fn (string $value) => ctype_digit($value) && (int) $value > 0)
        ->map(fn (string $value) => (int) $value)
        ->unique()
        ->values()
        ->all(),

    'shadow_log_differences' => env('COORDINATION_AUTHORIZATION_SHADOW_LOG', true),

    /*
    | Header yokken tek uyelik dogrudan kullanilir. Coklu uyelikte "primary"
    | ana uyeligi secerek eski istemcilere kontrollu gecis saglar. "none"
    | secilirse coklu uyelik her istekte header gondermek zorundadir.
    */
    'active_unit_fallback' => env('COORDINATION_ACTIVE_UNIT_FALLBACK', 'primary'),
];
