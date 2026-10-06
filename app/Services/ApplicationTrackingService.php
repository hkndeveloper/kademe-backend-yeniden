<?php

namespace App\Services;

use App\Models\Application;
use App\Support\ApplicationMailLinks;

class ApplicationTrackingService
{
    public function issue(Application $application): ?string
    {
        if (! $application->candidate_id) {
            return ApplicationMailLinks::portal($application->user, 'applications');
        }
        $expires = $application->tracking_expires_at ?? now()->addMonths(6);
        $token = hash_hmac('sha256', $application->id.'|'.$application->candidate_id.'|'.$expires->getTimestamp(), (string) config('app.key'));
        $application->update(['tracking_token_hash' => hash('sha256', $token), 'tracking_expires_at' => $expires]);

        return ApplicationMailLinks::absolute('/applications/track/'.$application->id.'#token='.$token);
    }

    public function resolve(int $id, string $token): Application
    {
        $application = Application::findOrFail($id);
        abort_unless($application->candidate_id && $application->tracking_token_hash
            && $application->tracking_expires_at?->isFuture()
            && hash_equals($application->tracking_token_hash, hash('sha256', $token)), 404);

        return $application;
    }
}
