<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Services\PermissionResolver;

class ProjectPolicy
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    /**
     * Tüm kurallardan önce çalışır (Super Admin her şeye yetkilidir)
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    /**
     * Ziyaretçiler dahil herkes aktif projeleri görebilir (Bu public methodda geçerli)
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Koordinatör kendi atandığı projeyi yönetebilir
     */
    public function update(User $user, Project $project): bool
    {
        return $this->permissionResolver->canAccessProject(
            $user,
            'projects.application_form.update',
            $project->id
        );
    }

    /**
     * Sadece Super Admin silebilir
     */
    public function delete(User $user, Project $project): bool
    {
        return false; // before metodu sayesinde super_admin zaten geçecek
    }
}
