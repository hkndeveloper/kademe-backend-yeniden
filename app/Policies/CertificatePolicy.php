<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CertificatePolicy
{
    public function before(User $user, string $ability): bool|null
    {
        if ($user->hasRole('super_admin')) return true;
        return null;
    }

    /**
     * Sertifikayı sadece sahibi veya projenin koordinatörü indirebilir/görebilir
     */
    public function view(User $user, Certificate $certificate): bool
    {
        if ($user->id === $certificate->user_id) {
            return true;
        }

        if ($user->hasRole('coordinator') && $certificate->project_id) {
            return $certificate->project->coordinators()->where('user_id', $user->id)->exists();
        }

        return false;
    }
}
