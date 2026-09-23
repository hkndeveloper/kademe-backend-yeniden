<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;
use App\Services\PermissionResolver;

class ApplicationPolicy
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

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

        return $this->permissionResolver->canAccessProject(
            $user,
            'applications.view',
            $application->project_id
        );
    }
}
