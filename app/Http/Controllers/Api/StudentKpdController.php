<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\KpdAppointmentResource;
use App\Models\KpdAppointment;
use App\Models\KpdRoom;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentKpdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $appointments = KpdAppointment::with([
            'counselor:id,name,surname,role',
            'counselee:id,name,surname',
            'room:id,name,description',
        ])
            ->where('counselee_id', $user->id)
            ->orderByDesc('start_at')
            ->get();

        $counselors = User::query()
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'surname', 'role'])
            ->map(fn (User $counselor) => [
                'id' => $counselor->id,
                'name' => $counselor->name,
                'surname' => $counselor->surname,
                'role' => $counselor->role,
            ])
            ->values();

        $rooms = KpdRoom::query()
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (KpdRoom $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'description' => $room->description,
            ])
            ->values();

        return response()->json([
            'appointments' => KpdAppointmentResource::collection($appointments),
            'counselors' => $counselors,
            'rooms' => $rooms,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'counselor_id' => 'required|exists:users,id',
            'room_id' => 'required|exists:kpd_rooms,id',
            'start_at' => 'required|date|after:now',
            'end_at' => 'required|date|after:start_at',
            'notes' => 'nullable|string|max:2000',
        ]);

        $counselor = User::query()
            ->whereKey($validated['counselor_id'])
            ->whereIn('role', ['super_admin', 'coordinator', 'staff'])
            ->first();

        if (! $counselor) {
            return response()->json([
                'message' => 'Secilen danisman uygun degil.',
            ], 422);
        }

        $timeConflict = KpdAppointment::query()
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($validated, $request) {
                $query->where('room_id', $validated['room_id'])
                    ->orWhere('counselor_id', $validated['counselor_id'])
                    ->orWhere('counselee_id', $request->user()->id);
            })
            ->where('start_at', '<', $validated['end_at'])
            ->where('end_at', '>', $validated['start_at'])
            ->exists();

        if ($timeConflict) {
            return response()->json([
                'message' => 'Secilen zaman araliginda uygun bir randevu olusturulamadi. Farkli saat veya oda deneyin.',
            ], 422);
        }

        $appointment = KpdAppointment::create([
            'counselor_id' => $validated['counselor_id'],
            'counselee_id' => $request->user()->id,
            'room_id' => $validated['room_id'],
            'start_at' => $validated['start_at'],
            'end_at' => $validated['end_at'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'scheduled',
        ])->load([
            'counselor:id,name,surname,role',
            'counselee:id,name,surname',
            'room:id,name,description',
        ]);

        return response()->json([
            'message' => 'KPD randevu talebin olusturuldu.',
            'appointment' => new KpdAppointmentResource($appointment),
        ], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $appointment = KpdAppointment::query()
            ->where('counselee_id', $request->user()->id)
            ->findOrFail($id);

        if ($appointment->status !== 'scheduled') {
            return response()->json([
                'message' => 'Yalnizca planlanmis randevular iptal edilebilir.',
            ], 422);
        }

        if ($appointment->start_at !== null && $appointment->start_at->isPast()) {
            return response()->json([
                'message' => 'Baslamis veya gecmis randevu iptal edilemez.',
            ], 422);
        }

        $appointment->update(['status' => 'cancelled']);
        $appointment->load([
            'counselor:id,name,surname,role',
            'counselee:id,name,surname',
            'room:id,name,description',
        ]);

        return response()->json([
            'message' => 'Randevu iptal edildi.',
            'appointment' => new KpdAppointmentResource($appointment),
        ]);
    }
}
