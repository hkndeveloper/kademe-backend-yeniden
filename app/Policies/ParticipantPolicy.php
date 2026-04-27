<?php

namespace App\Policies;

use App\Models\Participant;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ParticipantPolicy
{
    /**
     * Tüm kurallardan önce çalışır
     */
    public function before(User $user, string $ability): bool|null
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

        if ($user->hasRole('coordinator')) {
            return $participant->project->coordinators()->where('user_id', $user->id)->exists();
        }

        return false;
    }

    /**
     * Öğrencinin kredisini, mezuniyetini vs. sadece o projenin koordinatörü değiştirebilir
     */
    public function update(User $user, Participant $participant): bool
    {
        if ($user->hasRole('coordinator')) {
            return $participant->project->coordinators()->where('user_id', $user->id)->exists();
        }

        return false;
    }
}
