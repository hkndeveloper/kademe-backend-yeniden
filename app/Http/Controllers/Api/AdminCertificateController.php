<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCertificateController extends Controller
{
    use AuthorizesGranularPermissions;

    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function scopedCertificateQuery(Request $request, string $permission)
    {
        $query = Certificate::with(['project:id,name', 'user:id,name,surname,email']);

        if (! $this->permissionResolver->hasGlobalScope($request->user(), $permission)) {
            $projectIds = $this->permissionResolver->projectIdsForPermission($request->user(), $permission);
            $query->whereIn('project_id', $projectIds);
        }

        return $query;
    }

    /**
     * Tüm sertifikaları listele. (Admin paneli için)
     */
    public function index(Request $request)
    {
        $this->abortUnlessAllowed($request, 'certificates.view');

        $query = $this->scopedCertificateQuery($request, 'certificates.view');

        if ($request->filled('project_id')) {
            $projectId = (int) $request->project_id;
            abort_unless(
                $this->permissionResolver->canAccessProject($request->user(), 'certificates.view', $projectId),
                403,
                'Bu proje icin sertifika goruntuleme yetkiniz yok.',
            );
            $query->where('project_id', $projectId);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($builder) use ($s) {
                $builder->whereHas('user', function ($q) use ($s) {
                    $q->where('name', 'like', "%$s%")
                        ->orWhere('surname', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%");
                })->orWhere('verification_code', 'like', "%$s%");
            });
        }

        $certificates = $query->orderByDesc('issued_at')->paginate(20);

        return response()->json([
            'certificates' => $certificates,
        ]);
    }

    public function export(Request $request)
    {
        $this->abortUnlessAllowed($request, 'certificates.export');

        $query = $this->scopedCertificateQuery($request, 'certificates.export');

        if ($request->filled('project_id')) {
            $projectId = (int) $request->project_id;
            abort_unless(
                $this->permissionResolver->canAccessProject($request->user(), 'certificates.export', $projectId),
                403,
                'Bu proje icin sertifika disa aktarma yetkiniz yok.',
            );
            $query->where('project_id', $projectId);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($builder) use ($s) {
                $builder->whereHas('user', function ($q) use ($s) {
                    $q->where('name', 'like', "%$s%")
                        ->orWhere('surname', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%");
                })->orWhere('verification_code', 'like', "%$s%");
            });
        }

        $certificates = $query->orderByDesc('issued_at')->get();
        $headings = ['ID', 'Ad', 'Soyad', 'E-posta', 'Proje', 'Tur', 'Dogrulama Kodu', 'Verilis Tarihi'];
        $rows = $certificates->map(fn (Certificate $certificate) => [
            $certificate->id,
            $certificate->user?->name ?? '-',
            $certificate->user?->surname ?? '-',
            $certificate->user?->email ?? '-',
            $certificate->project?->name ?? '-',
            $certificate->type,
            $certificate->verification_code,
            $certificate->issued_at?->format('d.m.Y') ?? '-',
        ])->all();

        return AdminExportResponder::download(
            $request->string('format')->toString() ?: 'csv',
            'sertifikalar_' . now()->format('Ymd_His'),
            'Sertifikalar',
            $headings,
            $rows,
        );
    }

    /**
     * Yeni sertifika oluştur. (Admin)
     */
    public function store(Request $request)
    {
        $this->abortUnlessAllowed($request, 'certificates.create');

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'project_id' => 'required|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'type' => 'required|string',
            'file_path' => 'nullable|string',
        ]);

        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'certificates.create', (int) $validated['project_id']),
            403,
            'Bu projede sertifika olusturma yetkiniz yok.',
        );

        $exists = Certificate::where('user_id', $validated['user_id'])
            ->where('project_id', $validated['project_id'])
            ->where('type', $validated['type'])
            ->first();

        if ($exists) {
            return response()->json(['message' => 'Bu kullanıcıya bu projeden zaten bu türde bir sertifika verilmiş.'], 400);
        }

        $certificate = Certificate::create([
            'user_id' => $validated['user_id'],
            'project_id' => $validated['project_id'],
            'period_id' => $validated['period_id'] ?? null,
            'type' => $validated['type'],
            'verification_code' => strtoupper(Str::random(10)),
            'issued_at' => now(),
            'file_path' => $validated['file_path'] ?? null,
        ]);

        return response()->json([
            'message' => 'Sertifika başarıyla oluşturuldu.',
            'certificate' => $certificate->load(['user', 'project']),
        ], 201);
    }

    /**
     * Sertifikayı sil / iptal et. (Admin)
     */
    public function destroy(Request $request, int $id)
    {
        $this->abortUnlessAllowed($request, 'certificates.delete');

        $certificate = Certificate::findOrFail($id);

        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'certificates.delete', (int) $certificate->project_id),
            403,
            'Bu sertifikayi silme yetkiniz yok.',
        );

        $certificate->delete();

        return response()->json(['message' => 'Sertifika başarıyla iptal edildi/silindi.']);
    }
}
