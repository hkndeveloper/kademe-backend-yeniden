<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationCandidate;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AcceptedApplicantAccountService
{
    /** Must run inside the admission decision transaction. */
    public function resolve(Application $application): User
    {
        if ($application->user_id) {
            return User::lockForUpdate()->findOrFail($application->user_id);
        }
        $candidate = ApplicationCandidate::lockForUpdate()->findOrFail($application->candidate_id);
        $user = User::whereRaw('LOWER(email) = ?', [$candidate->email])->lockForUpdate()->first();
        if (! $user) {
            $user = User::create([
                'name' => $candidate->name, 'surname' => $candidate->surname, 'email' => $candidate->email,
                'phone' => $candidate->phone, 'password' => Hash::make(Str::random(64)),
                'role' => 'student', 'status' => 'active', 'email_verified_at' => $candidate->email_verified_at,
                'must_change_password' => true,
            ]);
            Role::findOrCreate('student', 'web');
            $user->assignRole('student');
            $application->accountCreatedOnAcceptance = true;
        }
        $candidate->update(['user_id' => $user->id]);
        Application::where('candidate_id', $candidate->id)->whereNull('user_id')->update(['user_id' => $user->id]);
        $application->user_id = $user->id;
        $application->setRelation('user', $user);

        return $user;
    }
}
