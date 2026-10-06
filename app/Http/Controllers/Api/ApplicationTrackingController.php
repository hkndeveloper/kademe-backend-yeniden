<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApplicationTrackingService;
use Illuminate\Http\Request;

class ApplicationTrackingController extends Controller
{
    public function show(Request $request, int $id, ApplicationTrackingService $tracking)
    {
        $application = $tracking->resolve($id, (string) $request->header('X-Application-Token'));

        return response()->json(['application' => [
            'id' => $application->id, 'project' => $application->project?->only(['id', 'name', 'slug']),
            'training' => $application->training?->only(['id', 'title']), 'status' => $application->status,
            'interview_at' => $application->interview_at, 'rejection_reason' => $application->rejection_reason,
            'waitlist_invitation_expires_at' => $application->waitlist_invitation_expires_at,
            'can_respond_waitlist' => $application->status === 'waitlisted' && $application->waitlist_invited_at
                && $application->waitlist_invitation_delivery_status === 'sent' && $application->waitlist_invitation_expires_at?->isFuture(),
            'account_available' => $application->status === 'accepted' && $application->user_id !== null,
        ]])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function respond(Request $request, int $id, ApplicationTrackingService $tracking, ApplicationController $controller)
    {
        $application = $tracking->resolve($id, (string) $request->header('X-Application-Token'));

        return $controller->respondWaitlistInvitation($request, $id, $application)->header('Cache-Control', 'no-store');
    }
}
