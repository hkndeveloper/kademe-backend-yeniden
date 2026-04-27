<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Participant;
use Illuminate\Http\Request;

class AssignmentController extends Controller
{
    /**
     * Öğrencinin aktif ödevlerini ve teslim durumlarını listeler
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Aktif katılım sağladığı projelerin/dönemlerin ID'lerini bul
        $participations = Participant::where('user_id', $user->id)
            ->where('status', 'active')
            ->get();

        $projectIds = $participations->pluck('project_id');
        $periodIds = $participations->pluck('period_id');

        // Bu dönemlere ait ödevleri çek
        $assignments = Assignment::whereIn('project_id', $projectIds)
            ->whereIn('period_id', $periodIds)
            // Öğrencinin teslim durumunu (submission) relation olarak dahil et (eğer varsa)
            ->with(['submissions' => function($query) use ($user) {
                $query->where('user_id', $user->id);
            }])
            ->orderBy('due_date', 'asc')
            ->get();

        return response()->json([
            'assignments' => $assignments
        ]);
    }

    /**
     * Ödev Teslimi (Gönderme)
     */
    public function submit(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'nullable|string',
            'description' => 'required|string',
            'file_path' => 'nullable|string', // AWS S3 veya R2 URL'si olabilir
        ]);

        $assignment = Assignment::findOrFail($id);
        $user = $request->user();

        // Daha önce teslim edilmiş mi kontrolü
        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            // İstersek güncelleyebiliriz, şu anlık üzerine yazalım
            $existing->update([
                'title' => $validated['title'] ?? $existing->title,
                'description' => $validated['description'],
                'file_path' => $validated['file_path'] ?? $existing->file_path,
                'status' => 'submitted'
            ]);
            
            return response()->json(['message' => 'Ödev tesliminiz güncellendi.', 'submission' => $existing]);
        }

        // Yeni teslim
        $submission = AssignmentSubmission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'file_path' => $validated['file_path'],
            'status' => 'submitted'
        ]);

        return response()->json([
            'message' => 'Ödeviniz başarıyla sisteme yüklendi.',
            'submission' => $submission
        ], 201);
    }
}
