<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\KpdAppointment;
use App\Services\PermissionResolver;
use Illuminate\Http\Request;

class AdminKpdController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Tüm KPD Randevularını Listele
     */
    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'kpd.appointments.view');

        $appointments = KpdAppointment::with(['counselor:id,name,surname', 'counselee:id,name,surname', 'room'])
            ->orderBy('start_at', 'desc')
            ->paginate(15);

        return response()->json(['appointments' => $appointments]);
    }

    /**
     * Yeni Randevu Oluştur
     */
    public function store(Request $request)
    {
        $this->abortUnlessAllowed($request, 'kpd.appointments.manage');

        $validated = $request->validate([
            'counselor_id' => 'required|exists:users,id',
            'counselee_id' => 'required|exists:users,id',
            'room_id' => 'required|exists:kpd_rooms,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'notes' => 'nullable|string',
        ]);

        $exists = KpdAppointment::where('room_id', $validated['room_id'])
            ->where(function ($q) use ($validated) {
                $q->whereBetween('start_at', [$validated['start_at'], $validated['end_at']])
                    ->orWhereBetween('end_at', [$validated['start_at'], $validated['end_at']]);
            })->exists();

        if ($exists) {
            return response()->json(['message' => 'Seçilen oda bu saatlerde dolu.'], 400);
        }

        $appointment = KpdAppointment::create(array_merge($validated, ['status' => 'scheduled']));

        return response()->json([
            'message' => 'Randevu başarıyla oluşturuldu.',
            'appointment' => $appointment,
        ], 201);
    }
}
