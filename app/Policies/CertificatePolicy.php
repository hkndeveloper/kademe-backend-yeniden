<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;
use App\Services\PermissionResolver;

class CertificatePolicy
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
     * Sertifikayı sadece sahibi veya projenin koordinatörü indirebilir/görebilir
     */
    public function view(User $user, Certificate $certificate): bool
    {
        if ($user->id === $certificate->user_id) {
            return true;
        }

        return $this->permissionResolver->canAccessProject(
            $user,
            'certificates.view',
            $certificate->project_id
        );
    }
}
