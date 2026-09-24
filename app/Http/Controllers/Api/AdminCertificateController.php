<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesGranularPermissions;
use App\Http\Controllers\Concerns\ResolvesProjectPeriodContext;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Participant;
use App\Services\NotificationService;
use App\Services\PermissionResolver;
use App\Support\AdminExportResponder;
use App\Support\CertificatePdfGenerator;
use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

/**
 * @group Certificates
 */
class AdminCertificateController extends Controller
{
    use AuthorizesGranularPermissions;
    use ResolvesProjectPeriodContext;

    private const CERTIFICATE_TYPES = [
        'participation',
        'graduation',
        'achievement',
    ];

    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly NotificationService $notificationService,
    ) {
    }

    private function scopedCertificateQuery(Request $request, string $permission)
    {
        $query = Certificate::with(['project:id,name', 'period:id,name,status', 'user:id,name,surname,email']);

        if (! $this->permissionResolver->hasGlobalScope($request->user(), $permission)) {
            $projectIds = $this->permissionResolver->projectIdsForPermission($request->user(), $permission);
            $query->whereIn('project_id', $projectIds);
        }

        return $query;
    }

    private function normalizeCertificateType(string $type): string
    {
        $normalized = Str::of($type)->lower()->ascii()->trim()->toString();

        return match ($normalized) {
            'katilim', 'katilim belgesi', 'participation' => 'participation',
            'mezuniyet', 'mezuniyet belgesi', 'graduation' => 'graduation',
            'basari', 'basari sertifikasi', 'onur', 'onur belgesi', 'achievement' => 'achievement',
            default => $normalized,
        };
    }

    private function attachCertificateAudit(Request $request, Certificate $certificate, string $operation): void
    {
        $request->attributes->set('audit.subject', $certificate);
        $request->attributes->set('audit.event', 'certificate.' . $operation);
        $request->attributes->set('audit.description', 'certificate.' . $operation);
        $request->attributes->set('audit.properties', [
            'operation' => 'certificate_' . $operation,
            'certificate_id' => $certificate->id,
            'project_id' => $certificate->project_id,
            'period_id' => $certificate->period_id,
            'student_user_id' => $certificate->user_id,
            'type' => $certificate->type,
            'verification_code' => $certificate->verification_code,
            'certificate_path' => $certificate->certificate_path,
            'file_present' => ! empty($certificate->certificate_path),
            'issued_at' => optional($certificate->issued_at)?->toIso8601String(),
        ]);
    }

    /**
     * List panel certificates.
     *
     * Panel/admin endpoint exposed under `/admin/certificates` and `/panel/certificates`. Uses `certificates.view` through project-period context: global scope sees all project certificates, while scoped users are limited to allowed project ids. Optional `project_id` and `period_id` are validated against the action+scope matrix.
     *
     * Returns paginated certificate records with user, project, period, verification code, stored path, and public download URL.
     *
     * @group Certificates
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter; scoped by `certificates.view`. Example: 1
     * @queryParam period_id integer Optional period filter; must belong to the selected/allowed project. Example: 3
     * @queryParam search string Optional recipient name, surname, email, or verification code search. Example: ABC123
     * @response 200 {"certificates":{"data":[{"id":1,"type":"participation","verification_code":"ABC123","download_url":"https://api.example.com/api/certificates/ABC123/download","user":{"id":5,"name":"Ayse"}}]}}
     * @response 403 {"message":"Bu islem icin yetkiniz yok."}
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'search' => 'nullable|string|max:255',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'certificates.view',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Certificate::with(['project:id,name', 'period:id,name,status', 'user:id,name,surname,email']);
        $this->applyProjectPeriodContext($query, $context);

        if (! empty($validated['search'])) {
            $s = $validated['search'];
            $query->where(function ($builder) use ($s) {
                $builder->whereHas('user', function ($q) use ($s) {
                    $q->where('name', 'like', "%$s%")
                        ->orWhere('surname', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%");
                })->orWhere('verification_code', 'like', "%$s%");
            });
        }

        $certificates = $query->orderByDesc('issued_at')->orderByDesc('created_at')->paginate(20)->through(fn (Certificate $certificate) => [
            'id' => $certificate->id,
            'type' => $certificate->type,
            'verification_code' => $certificate->verification_code,
            'issued_at' => $certificate->issued_at,
            'certificate_path' => $certificate->certificate_path,
            'download_url' => $certificate->certificate_path
                ? url("/api/certificates/{$certificate->verification_code}/download")
                : null,
            'project' => $certificate->project,
            'period' => $certificate->period,
            'user' => $certificate->user,
        ]);

        return response()->json([
            'certificates' => $certificates,
        ]);
    }

    /**
     * Export panel certificates.
     *
     * Panel/admin endpoint exposed under `/admin/certificates/export` and `/panel/certificates/export`. Uses `certificates.export` through project-period context: global scope exports all matching certificates, while scoped users are limited to allowed project ids. The shared export responder accepts `csv`, `xlsx`, or `pdf` when enabled.
     *
     * @group Certificates
     * @authenticated
     *
     * @queryParam project_id integer Optional project filter; scoped by `certificates.export`. Example: 1
     * @queryParam period_id integer Optional period filter; must belong to the selected/allowed project. Example: 3
     * @queryParam search string Optional recipient name, surname, email, or verification code search. Example: hakan@example.com
     * @queryParam format string Optional export format. Example: xlsx
     * @response 200 {"download":"Export file stream"}
     * @response 403 {"message":"Bu islem icin yetkiniz yok."}
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'search' => 'nullable|string|max:255',
            'format' => 'nullable|string|max:20',
        ]);
        $context = $this->resolveProjectPeriodContext(
            $request,
            'certificates.export',
            ! empty($validated['project_id']) ? (int) $validated['project_id'] : null,
            ! empty($validated['period_id']) ? (int) $validated['period_id'] : null,
        );

        $query = Certificate::with(['project:id,name', 'period:id,name,status', 'user:id,name,surname,email']);
        $this->applyProjectPeriodContext($query, $context);

        if (! empty($validated['search'])) {
            $s = $validated['search'];
            $query->where(function ($builder) use ($s) {
                $builder->whereHas('user', function ($q) use ($s) {
                    $q->where('name', 'like', "%$s%")
                        ->orWhere('surname', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%");
                })->orWhere('verification_code', 'like', "%$s%");
            });
        }

        $certificates = $query->orderByDesc('issued_at')->orderByDesc('created_at')->get();
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
     * Create a certificate.
     *
     * Panel/admin endpoint exposed under `/admin/certificates` and `/panel/certificates`. Requires `certificates.create` and create access to the selected project. `period_id`, when provided, must belong to the project and must be writable; completed periods require archive update permission through the shared period lock logic.
     *
     * The `type` field is normalized from Turkish/English labels into `participation`, `graduation`, or `achievement`. Duplicate certificates for the same user, project, period, and type are rejected. If no file/path is supplied, the backend generates a certificate PDF, assigns a verification code, stores it, audit logs `certificate.created`, and emails the recipient when an email exists.
     *
     * @group Certificates
     * @authenticated
     *
     * @bodyParam user_id integer required Recipient user id. Example: 5
     * @bodyParam project_id integer required Project id; scoped by `certificates.create`. Example: 1
     * @bodyParam period_id integer Optional period id belonging to the selected project. Example: 3
     * @bodyParam type string required Certificate type: participation, graduation, achievement. Turkish aliases such as katilim, mezuniyet, basari are normalized. Example: participation
     * @bodyParam certificate_path string Optional existing storage path or URL. Example: certificates/manual.pdf
     * @bodyParam file_path string Optional alias for certificate_path. Example: certificates/manual.pdf
     * @bodyParam certificate_file file Optional certificate file; pdf, jpg, jpeg, png, max 20MB.
     * @response 201 {"message":"Sertifika basariyla olusturuldu.","certificate":{"id":1,"type":"participation","verification_code":"ABC123"}}
     * @response 400 {"message":"Bu kullaniciya bu projeden zaten bu turde bir sertifika verilmis."}
     * @response 403 {"message":"Bu projede sertifika olusturma yetkiniz yok."}
     * @response 422 {"message":"Secilen donem bu projeye ait degil."}
     */
    public function store(Request $request)
    {
        $this->abortUnlessAllowed($request, 'certificates.create');
        $request->merge([
            'type' => $this->normalizeCertificateType((string) $request->input('type', '')),
        ]);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'project_id' => 'required|exists:projects,id',
            'period_id' => 'nullable|exists:periods,id',
            'type' => ['required', 'string', Rule::in(self::CERTIFICATE_TYPES)],
            'certificate_path' => 'nullable|string|max:2048',
            'file_path' => 'nullable|string|max:2048',
            'certificate_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        abort_unless(
            $this->permissionResolver->canAccessProject($request->user(), 'certificates.create', (int) $validated['project_id']),
            403,
            'Bu projede sertifika olusturma yetkiniz yok.',
        );

        if (! $this->permissionResolver->hasGlobalScope($request->user(), 'certificates.create')) {
            abort_unless(
                Participant::query()
                    ->where('user_id', (int) $validated['user_id'])
                    ->where('project_id', (int) $validated['project_id'])
                    ->exists(),
                422,
                'Sertifika alicisi secilen projenin katilimcisi veya mezunu olmalidir.'
            );
        }

        if (! empty($validated['period_id'])) {
            abort_unless(
                \App\Models\Period::query()
                    ->whereKey((int) $validated['period_id'])
                    ->where('project_id', (int) $validated['project_id'])
                    ->exists(),
                422,
                'Secilen donem bu projeye ait degil.'
            );
            $this->assertPeriodResolvable($request, (int) $validated['period_id']);
        }

        $exists = Certificate::where('user_id', $validated['user_id'])
            ->where('project_id', $validated['project_id'])
            ->where('period_id', $validated['period_id'] ?? null)
            ->where('type', $validated['type'])
            ->first();

        if ($exists) {
            return response()->json(['message' => 'Bu kullanıcıya bu projeden zaten bu türde bir sertifika verilmiş.'], 400);
        }

        $certificatePath = $validated['certificate_path'] ?? $validated['file_path'] ?? null;
        if ($request->hasFile('certificate_file')) {
            $certificatePath = MediaStorage::putFile('certificates', $request->file('certificate_file'));
        }

        $certificate = Certificate::create([
            'user_id' => $validated['user_id'],
            'project_id' => $validated['project_id'],
            'period_id' => $validated['period_id'] ?? null,
            'type' => $validated['type'],
            'verification_code' => strtoupper(Str::random(10)),
            'issued_at' => now(),
            'certificate_path' => $certificatePath,
            'created_by' => $request->user()->id,
        ]);

        if (empty($certificate->certificate_path)) {
            $certificate = CertificatePdfGenerator::generate($certificate);
        }

        $this->attachCertificateAudit($request, $certificate, 'created');

        $certificate->loadMissing(['user:id,email,name,surname', 'project:id,name']);
        $email = $certificate->user?->email;
        if ($email) {
            $this->notificationService->sendEmail(
                [$email],
                'Sertifikaniz olusturuldu',
                "Merhaba {$certificate->user?->name},\nProje: {$certificate->project?->name}\nSertifika turu: {$certificate->type}\nDogrulama kodu: {$certificate->verification_code}",
                $certificate->project_id,
                $request->user()->id
            );
        }

        return response()->json([
            'message' => 'Sertifika başarıyla oluşturuldu.',
            'certificate' => $certificate->load(['user', 'project']),
        ], 201);
    }

    /**
     * Delete a certificate.
     *
     * Panel/admin endpoint exposed under `/admin/certificates/{id}` and `/panel/certificates/{id}`. Requires `certificates.delete` and delete access to the certificate project. The related period must be writable; completed periods require archive update permission. The certificate file is removed from storage and the operation is audit logged as `certificate.deleted`.
     *
     * @group Certificates
     * @authenticated
     *
     * @urlParam id integer required Certificate id. Example: 1
     * @response 200 {"message":"Sertifika basariyla iptal edildi/silindi."}
     * @response 403 {"message":"Bu sertifikayi silme yetkiniz yok."}
     * @response 423 {"message":"Tamamlanmis donem arsiv modundadir. Degisiklik icin arsiv duzeltme yetkisi gerekir."}
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
        $this->assertPeriodWritable($request, $certificate->period_id);

        MediaStorage::delete($certificate->certificate_path);
        $this->attachCertificateAudit($request, $certificate, 'deleted');
        $certificate->delete();

        return response()->json(['message' => 'Sertifika başarıyla iptal edildi/silindi.']);
    }
}
