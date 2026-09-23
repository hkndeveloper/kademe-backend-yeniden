<?php

namespace App\Policies;

use App\Models\Participant;
use App\Models\User;
use App\Services\PermissionResolver;

class ParticipantPolicy
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {}

    /**
     * Tüm kurallardan önce çalışır
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    /**
     * Öğrenci kendi katılımcı kaydını görebilir
     * Koordinatör ise projenin koordinatörüyse görebilir
     */
    public function view(User $user, Participant $participant): bool
    {
        if ($user->id === $participant->user_id) {
            return true;
        }

        return $this->permissionResolver->canAccessProject(
            $user,
            'projects.participants.view',
            $participant->project_id
        );
    }

    /**
     * Öğrencinin kredisini, mezuniyetini vs. sadece o projenin koordinatörü değiştirebilir
     */
    public function update(User $user, Participant $participant): bool
    {
        return $this->permissionResolver->canAccessProject(
            $user,
            'projects.participants.manage',
            $participant->project_id
        );
    }
}
