<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    /**
     * Tüm kurallardan önce çalışır (Super Admin her şeye yetkilidir)
     */
    public function before(User $user, string $ability): bool|null
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
        return $user->hasRole('coordinator') && $project->coordinators()->where('user_id', $user->id)->exists();
    }

    /**
     * Sadece Super Admin silebilir
     */
    public function delete(User $user, Project $project): bool
    {
        return false; // before metodu sayesinde super_admin zaten geçecek
    }
}
