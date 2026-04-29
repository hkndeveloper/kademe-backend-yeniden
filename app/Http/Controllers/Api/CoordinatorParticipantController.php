<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\Project;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoordinatorParticipantController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function mediaUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }

    public function index(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'projects.participants.view');
        $coordinator = $request->user();
        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($coordinator, 'projects.participants.view');

        $query = Participant::with([
            'project:id,name',
            'period:id,name',
            'user:id,name,surname,email,phone,university,department,class_year,hometown,profile_photo_path,status',
        ])->whereIn('project_id', $manageableProjectIds);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('graduation_status')) {
            $query->where('graduation_status', $request->graduation_status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($builder) use ($search) {
                $builder->where(function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%$search%")
                        ->orWhere('surname', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%")
                        ->orWhere('university', 'like', "%$search%")
                        ->orWhere('department', 'like', "%$search%");
                });
            });
        }

        $participants = $query->orderByDesc('created_at')->get();

        return response()->json([
            'projects' => Project::query()
                ->whereIn('id', $manageableProjectIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'summary' => [
                'total' => $participants->count(),
                'active' => $participants->where('status', 'active')->count(),
                'graduates' => $participants->filter(fn ($participant) =>
                    ! is_null($participant->graduated_at) || $participant->graduation_status === 'graduated'
                )->count(),
                'average_credit' => $participants->count() > 0
                    ? round($participants->avg('credit') ?? 0, 1)
                    : 0,
            ],
            'participants' => $participants->map(function ($participant) {
                $user = $participant->user;

                return [
                    'id' => $participant->id,
                    'status' => $participant->status,
                    'graduation_status' => $participant->graduation_status,
                    'graduation_note' => $participant->graduation_note,
                    'credit' => $participant->credit,
                    'enrolled_at' => optional($participant->enrolled_at)?->toDateString(),
                    'graduated_at' => optional($participant->graduated_at)?->toDateString(),
                    'project' => [
                        'id' => $participant->project?->id,
                        'name' => $participant->project?->name,
                    ],
                    'period' => [
                        'id' => $participant->period?->id,
                        'name' => $participant->period?->name,
                    ],
                    'user' => [
                        'id' => $user?->id,
                        'name' => $user?->name,
                        'surname' => $user?->surname,
                        'email' => $user?->email,
                        'phone' => $user?->phone,
                        'university' => $user?->university,
                        'department' => $user?->department,
                        'class_year' => $user?->class_year,
                        'hometown' => $user?->hometown,
                        'status' => $user?->status,
                        'profile_photo' => $this->mediaUrl($user?->profile_photo_path),
                    ],
                ];
            })->values(),
        ]);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'projects.participants.view');
        $coordinator = $request->user();
        $manageableProjectIds = $this->permissionResolver->projectIdsForPermission($coordinator, 'projects.participants.view');

        $query = Participant::with([
            'project:id,name',
            'period:id,name',
            'user:id,name,surname,email,phone,university,department,class_year,hometown',
        ])->whereIn('project_id', $manageableProjectIds);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($builder) use ($search) {
                $builder->where(function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%$search%")
                        ->orWhere('surname', 'like', "%$search%")
                        ->orWhere('email', 'like', "%$search%")
                        ->orWhere('university', 'like', "%$search%")
                        ->orWhere('department', 'like', "%$search%");
                });
            });
        }

        $participants = $query->orderByDesc('created_at')->get();
        $headings = ['ID', 'Ad', 'Soyad', 'E-posta', 'Telefon', 'Proje', 'Donem', 'Universite', 'Bolum', 'Sinif', 'Kredi', 'Durum', 'Mezuniyet'];
        $rows = $participants->map(fn (Participant $participant) => [
            $participant->id,
            $participant->user?->name ?? '-',
            $participant->user?->surname ?? '-',
            $participant->user?->email ?? '-',
            $participant->user?->phone ?? '-',
            $participant->project?->name ?? '-',
            $participant->period?->name ?? '-',
            $participant->user?->university ?? '-',
            $participant->user?->department ?? '-',
            $participant->user?->class_year ?? '-',
            $participant->credit ?? 0,
            $participant->status,
            $participant->graduation_status ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'katilimcilar_' . now()->format('Ymd_His'),
            'Katilimcilar',
            $headings,
            $rows,
        );
    }
}
