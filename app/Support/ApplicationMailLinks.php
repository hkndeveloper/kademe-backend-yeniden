<?php

namespace App\Support;

use App\Models\User;

class ApplicationMailLinks
{
    public static function portal(?User $user, string $section): ?string
    {
        $role = $user?->role;
        if (! in_array($role, ['student', 'alumni'], true)) {
            return null;
        }

        return self::absolute('/'.$role.'/'.$section);
    }

    public static function absolute(string $path): ?string
    {
        $base = rtrim((string) config('services.frontend.url'), '/');
        if ($base === '' || ! filter_var($base, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        return $base.'/'.ltrim($path, '/');
    }
}
