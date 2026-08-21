<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\CreditLog;
use App\Models\Participant;
use App\Services\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Credits & Rewards
 */
class AdminCreditController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Ogrenciye manuel kredi ekleme/cikarma.
     */
    public function adjustCredit(Request $request)
    {
        $this->abortUnlessAllowed($request, 'projects.participants.manage');

        $validated = $request->validate([
            'participant_id' => 'required|exists:participants,id',
            'amount' => 'required|integer|not_in:0', // +10 veya -10 gibi
            'reason' => 'required|string|max:255',
        ]);

        $participant = Participant::findOrFail($validated['participant_id']);

        abort_unless(
            $this->permissionResolver->canAccessProject(
                $request->user(),
                'projects.participants.manage',
                (int) $participant->project_id
            ),
            403,
            'Bu katilimci icin yetkiniz bulunmuyor.'
        );
        $this->assertPeriodResolvable($request, $participant->period_id);

        DB::beginTransaction();
        try {
            $log = CreditLog::create([
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'project_id' => $participant->project_id,
                'period_id' => $participant->period_id,
                'amount' => $validated['amount'],
                'type' => 'manual_adjust',
                'reason' => $validated['reason'],
                'created_by' => $request->user()->id,
            ]);

            $participant->increment('credit', $validated['amount']);
            $participant->refresh();
            $log->load('creator:id,name,surname');

            DB::commit();

            return response()->json([
                'message' => 'Kredi basariyla guncellendi.',
                'current_credit' => (int) $participant->credit,
                'log' => $log,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Bir hata olustu.'], 500);
        }
    }

    /**
     * Ogrenciye manuel rozet verme.
     */
    public function awardBadge(Request $request)
    {
        $this->abortUnlessAllowed($request, 'projects.participants.manage');

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'badge_id' => 'required|exists:badges,id',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $user = \App\Models\User::findOrFail($validated['user_id']);

        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
        if ($projectId !== null) {
            abort_unless(
                $this->permissionResolver->canAccessProject(
                    $request->user(),
                    'projects.participants.manage',
                    $projectId
                ),
                403,
                'Bu proje icin yetkiniz bulunmuyor.'
            );
        }

        $hasBadge = $user->badges()
            ->where('badge_id', $validated['badge_id'])
            ->wherePivot('project_id', $validated['project_id'])
            ->exists();

        if ($hasBadge) {
            return response()->json(['message' => 'Kullanici bu rozete zaten sahip.'], 400);
        }

        $user->badges()->attach($validated['badge_id'], [
            'project_id' => $validated['project_id'],
            'awarded_at' => now(),
            'awarded_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Rozet basariyla tanimlandi.',
        ]);
    }
}
