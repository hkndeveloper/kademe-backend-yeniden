<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;

class ApplicationService
{
    /**
     * Kriterlere uymayan başvuruyu otomatik reddet
     */
    public function autoReject(Application $application, string $reason)
    {
        $application->update([
            'status' => 'rejected',
            'auto_rejected' => true,
            'auto_rejection_reason' => $reason
        ]);

        return $application;
    }

    /**
     * Kullanıcının kara listede olup olmadığını denetle
     */
    public function checkBlacklist(User $user): bool
    {
        if ($user->status === 'blacklisted') {
            if (!$user->blacklisted_until || now()->isBefore($user->blacklisted_until)) {
                return true;
            }
        }
        return false;
    }
}
