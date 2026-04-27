<?php

namespace App\Services;

use App\Models\User;

class BlacklistService
{
    /**
     * Kullanıcıyı kara listeye al (Varsayılan: 6 Ay)
     */
    public function apply(User $user, int $durationMonths = 6)
    {
        $user->update([
            'status' => 'blacklisted',
            'blacklist_count' => $user->blacklist_count + 1,
            'blacklisted_until' => now()->addMonths($durationMonths)
        ]);

        return $user;
    }

    /**
     * Kullanıcının kara liste durumunu manuel veya süresi dolunca kaldır
     */
    public function lift(User $user)
    {
        $user->update([
            'status' => 'active',
            'blacklisted_until' => null
        ]);

        return $user;
    }
}
