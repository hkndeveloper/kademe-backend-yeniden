<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\DigitalBohca;
use App\Models\Participant;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Support\MediaStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            ->map(fn (DigitalBohca $material) => $this->materialPayload($material, '/digital-bohca'));

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
            'materials' => $query->paginate(20)->through(
                fn (DigitalBohca $material) => $this->materialPayload($material, '/panel/digital-bohca')
            ),
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
        $path = MediaStorage::putFile('digital-bohca', $file);

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
            'material' => $this->materialPayload(
                $material->load(['project:id,name', 'user:id,name,surname,email', 'uploader:id,name,surname']),
                '/panel/digital-bohca'
            ),
        ], 201);
    }

    public function panelExport(Request $request)
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
                $builder->orWhere('uploaded_by', $user->id);
            });
        }

        if ($request->filled('project_id')) {
            $projectId = $request->integer('project_id');
            $query->where('project_id', $projectId);
        }

        $materials = $query->get();

        $headings = ['Kayit ID', 'Baslik', 'Proje', 'Hedef Kullanici', 'Dosya Tipi', 'Ogrenciye Gorunur', 'Yukleyen', 'Olusturma Tarihi'];
        $rows = $materials->map(fn (DigitalBohca $material) => [
            $material->id,
            $material->title,
            $material->project?->name ?? 'Genel',
            $material->user ? trim($material->user->name . ' ' . $material->user->surname) : '-',
            $material->file_type ?? '-',
            $material->visible_to_student ? 'Evet' : 'Hayir',
            $material->uploader ? trim($material->uploader->name . ' ' . $material->uploader->surname) : '-',
            $material->created_at?->format('d.m.Y H:i') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'digital_bohca_' . now()->format('Ymd_His'),
            'Digital Bohca',
            $headings,
            $rows,
        );
    }

    public function download(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        $projectIds = Participant::where('user_id', $user->id)
            ->where(function ($query) use ($user) {
                $query->where('status', 'active');

                if ($user->role === 'alumni') {
                    $query->orWhere('graduation_status', 'graduated')
                        ->orWhereNotNull('graduated_at');
                }
            })
            ->pluck('project_id');

        $material = DigitalBohca::query()
            ->whereKey($id)
            ->where('visible_to_student', true)
            ->where(function ($query) use ($projectIds, $user) {
                $query->whereIn('project_id', $projectIds)
                    ->orWhere('user_id', $user->id)
                    ->orWhereNull('project_id');
            })
            ->firstOrFail();

        return $this->downloadMaterial($material);
    }

    public function panelDownload(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $this->abortUnlessAllowed($request, 'digital_bohca.view');
        $material = DigitalBohca::query()->findOrFail($id);
        $user = $request->user();

        if ($material->project_id !== null) {
            $this->abortUnlessProjectAllowed($request, 'digital_bohca.view', (int) $material->project_id);
        } elseif (
            ! $this->permissionResolver->hasGlobalScope($user, 'digital_bohca.view')
            && (int) $material->uploaded_by !== (int) $user->id
        ) {
            abort(403, 'Genel bohca materyalini gormek icin global kapsam gerekir.');
        }

        return $this->downloadMaterial($material);
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

        MediaStorage::delete($material->file_path);
        $material->delete();

        return response()->json(['message' => 'Dijital bohca materyali silindi.']);
    }

    private function materialPayload(DigitalBohca $material, string $basePath): array
    {
        return [
            'id' => $material->id,
            'project_id' => $material->project_id,
            'user_id' => $material->user_id,
            'title' => $material->title,
            'description' => $material->description,
            'file_path' => $material->file_path,
            'file_url' => MediaStorage::directDownloadsEnabled() ? MediaStorage::url($material->file_path) : null,
            'download_url' => "{$basePath}/{$material->id}/download",
            'file_type' => $material->file_type,
            'visible_to_student' => $material->visible_to_student,
            'created_at' => optional($material->created_at)?->toIso8601String(),
            'updated_at' => optional($material->updated_at)?->toIso8601String(),
            'project' => $material->relationLoaded('project') ? $material->project : null,
            'user' => $material->relationLoaded('user') ? $material->user : null,
            'uploader' => $material->relationLoaded('uploader') ? $material->uploader : null,
        ];
    }

    private function downloadMaterial(DigitalBohca $material): JsonResponse|StreamedResponse
    {
        if (!$material->file_path) {
            return response()->json(['message' => 'Dosya bulunamadi.'], 404);
        }

        if (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured()) {
            return response()->json(['download_url' => MediaStorage::url($material->file_path)]);
        }

        if (!MediaStorage::exists($material->file_path)) {
            return response()->json(['message' => 'Dosya storage uzerinde bulunamadi.'], 404);
        }

        $extension = pathinfo($material->file_path, PATHINFO_EXTENSION);
        $filename = str($material->title)->slug()->toString() ?: 'digital-bohca';

        return MediaStorage::disk()->download($material->file_path, $filename . ($extension ? ".{$extension}" : ''));
    }
}
