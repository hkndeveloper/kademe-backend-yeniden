<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\DigitalBohca;
use App\Models\Participant;
use App\Services\PermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class DigitalBohcaController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    /**
     * Öğrencinin katıldığı projelere ait Dijital Bohça materyallerini listeler
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Aktif projelerinin ID'lerini bul
        $projectIds = Participant::where('user_id', $user->id)
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            })
            ->pluck('project_id');

        // Bu projelere ait, öğrenciye görünür olan dosyalar (veya genele açık olanlar)
        $materials = DigitalBohca::where(function($query) use ($projectIds, $user) {
                // Sadece belli bir projeye atananlar
                $query->whereIn('project_id', $projectIds)
                      // Veya sadece bu öğrenciye özel yüklenenler
                      ->orWhere('user_id', $user->id)
                      // Veya herkese açık genel dosyalar
                      ->orWhereNull('project_id');
            })
            ->where('visible_to_student', true)
            ->with('uploader:id,name,surname,role')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (DigitalBohca $material) {
                if ($material->file_path && ! str_starts_with($material->file_path, 'http://') && ! str_starts_with($material->file_path, 'https://')) {
                    $material->file_url = Storage::disk('public')->url($material->file_path);
                } else {
                    $material->file_url = $material->file_path;
                }

                return $material;
            });

        return response()->json([
            'materials' => $materials
        ]);
    }

    public function panelIndex(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'digital_bohca.view');
        $user = $request->user();
        $projectIds = $this->permissionResolver->projectIdsForPermission($user, 'digital_bohca.view');

        $query = DigitalBohca::query()
            ->with(['project:id,name', 'user:id,name,surname,email', 'uploader:id,name,surname'])
            ->orderByDesc('created_at');

        if (! $this->permissionResolver->hasGlobalScope($user, 'digital_bohca.view')) {
            $query->where(function ($builder) use ($projectIds, $user) {
                $builder->whereIn('project_id', $projectIds);

                if ($this->permissionResolver->hasPermission($user, 'digital_bohca.create')) {
                    $builder->orWhere('uploaded_by', $user->id);
                }
            });
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        return response()->json([
            'materials' => $query->paginate(20),
        ]);
    }

    public function panelStore(Request $request): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'digital_bohca.create');
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'user_id' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'file' => 'required|file|max:20480',
            'visible_to_student' => 'sometimes|boolean',
        ]);

        if (! empty($validated['project_id'])) {
            $this->abortUnlessProjectAllowed($request, 'digital_bohca.create', (int) $validated['project_id']);
        } elseif (! $this->permissionResolver->hasGlobalScope($request->user(), 'digital_bohca.create')) {
            abort(403, 'Genel bohca materyali olusturmak icin global kapsam gerekir.');
        }

        $file = $request->file('file');
        $path = $file->store('digital-bohca', 'public');

        $material = DigitalBohca::query()->create([
            'project_id' => $validated['project_id'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'file_path' => $path,
            'file_type' => $file->getClientOriginalExtension(),
            'visible_to_student' => $validated['visible_to_student'] ?? true,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Dijital bohca materyali yuklendi.',
            'material' => $material->load(['project:id,name', 'user:id,name,surname,email', 'uploader:id,name,surname']),
        ], 201);
    }

    public function panelDestroy(Request $request, int $id): JsonResponse
    {
        $this->abortUnlessAllowed($request, 'digital_bohca.delete');
        $material = DigitalBohca::query()->findOrFail($id);

        if ($material->project_id !== null) {
            $this->abortUnlessProjectAllowed($request, 'digital_bohca.delete', (int) $material->project_id);
        } elseif (! $this->permissionResolver->hasGlobalScope($request->user(), 'digital_bohca.delete')) {
            abort(403, 'Genel bohca materyali silmek icin global kapsam gerekir.');
        }

        Storage::disk('public')->delete($material->file_path);
        $material->delete();

        return response()->json(['message' => 'Dijital bohca materyali silindi.']);
    }
}
