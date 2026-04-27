<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ApplicationPolicy
{
    public function before(User $user, string $ability): bool|null
    {
        if ($user->hasRole('super_admin')) return true;
        return null;
    }

    /**
     * Öğrenci sadece KENDİ başvurusunu görebilir.
     * Koordinatör ise projesinin başvurularını görebilir.
     */
    public function view(User $user, Application $application): bool
    {
        if ($user->id === $application->user_id) {
            return true;
        }

        if ($user->hasRole('coordinator')) {
            return $application->project->coordinators()->where('user_id', $user->id)->exists();
        }

        return false;
    }
}
