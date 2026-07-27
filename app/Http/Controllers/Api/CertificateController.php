<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Certificates
 */
class CertificateController extends Controller
{
    /**
     * List authenticated user certificates.
     *
     * Participant/mobile endpoint. The route requires `participant.certificates.view` and the controller returns only certificates owned by the authenticated user. Each item includes verification code, project/period metadata, direct file URL when enabled, and the public download URL.
     *
     * @group Participant Content
     * @authenticated
     *
     * @response 200 {"certificates":[{"id":1,"type":"participation","verification_code":"ABC123","issued_at":"2026-01-01T00:00:00.000000Z","download_url":"https://api.example.com/api/certificates/ABC123/download","project":{"id":1,"name":"KADEME","slug":"kademe"}}]}
     * @response 401 {"message":"Unauthenticated."}
     * @response 403 {"message":"This action is unauthorized."}
     */
    public function index(Request $request)
    {
        $certificates = Certificate::with(['project:id,name,slug', 'period:id,name'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'certificates' => CertificateResource::collection($certificates),
        ]);
    }
    /**
     * Upload a participant-owned certificate.
     *
     * The authenticated participant can add external certificates to their own certificate library and CV selections. Uploaded certificates are marked as `student_upload` and do not require a project/period.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'issuer' => 'required|string|max:255',
            'type' => 'nullable|string|max:80',
            'issued_at' => 'nullable|date',
            'included_in_cv' => 'nullable|boolean',
            'certificate_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        $certificatePath = MediaStorage::putFile('certificates/student-uploads', $request->file('certificate_file'));

        $certificate = Certificate::query()->create([
            'user_id' => $request->user()->id,
            'project_id' => null,
            'period_id' => null,
            'type' => $validated['type'] ?? 'achievement',
            'title' => trim((string) $validated['title']),
            'issuer' => trim((string) $validated['issuer']),
            'verification_code' => strtoupper(Str::random(10)),
            'certificate_path' => $certificatePath,
            'issued_at' => $validated['issued_at'] ?? now(),
            'created_by' => $request->user()->id,
            'uploaded_by_user_id' => $request->user()->id,
            'source' => 'student_upload',
            'included_in_cv' => (bool) ($validated['included_in_cv'] ?? true),
        ]);

        return response()->json([
            'message' => 'Sertifika basariyla yuklendi.',
            'certificate' => CertificateResource::make($certificate->load(['project:id,name,slug', 'period:id,name'])),
        ], 201);
    }

    /**
     * Verify a certificate by code.
     *
     * Public endpoint used by web/mobile certificate verification screens. No bearer token is required. The response confirms validity and returns public certificate metadata plus recipient name/surname; it does not require panel permissions.
     *
     * @group Certificates
     * @unauthenticated
     *
     * @urlParam verificationCode string required Certificate verification code. Example: ABC123
     * @response 200 {"valid":true,"certificate":{"id":1,"type":"participation","verification_code":"ABC123","issued_at":"2026-01-01T00:00:00.000000Z"},"recipient":{"name":"Hakan","surname":"Kekec"}}
     * @response 404 {"message":"No query results for model [App\\Models\\Certificate]."}
     */
    public function verify(string $verificationCode)
    {
        $certificate = Certificate::with(['project:id,name,slug', 'period:id,name', 'user:id,name,surname'])
            ->where('verification_code', $verificationCode)
            ->firstOrFail();

        return response()->json([
            'valid' => true,
            'certificate' => CertificateResource::make($certificate),
            'recipient' => [
                'name' => $certificate->user?->name,
                'surname' => $certificate->user?->surname,
            ],
        ]);
    }

    /**
     * Download a certificate file by code.
     *
     * Public endpoint used after verification. No bearer token is required. Returns a storage direct URL when direct/public downloads are configured; otherwise streams the certificate file. Generated certificates are typically stored under the certificate media disk path.
     *
     * @group Certificates
     * @unauthenticated
     *
     * @urlParam verificationCode string required Certificate verification code. Example: ABC123
     * @response 200 {"download_url":"https://storage.example.com/certificates/abc123.pdf"}
     * @response 200 {"download":"Binary certificate file stream"}
     * @response 404 {"message":"Sertifika dosyasi bulunamadi."}
     * @response 404 {"message":"Sertifika dosyasi storage uzerinde bulunamadi."}
     */
    public function download(string $verificationCode): JsonResponse|StreamedResponse
    {
        $certificate = Certificate::query()
            ->where('verification_code', $verificationCode)
            ->firstOrFail();

        if (! $certificate->certificate_path) {
            return response()->json(['message' => 'Sertifika dosyasi bulunamadi.'], 404);
        }

        if (MediaStorage::directDownloadsEnabled() && MediaStorage::publicUrlConfigured()) {
            return response()->json([
                'download_url' => MediaStorage::url($certificate->certificate_path),
            ]);
        }

        if (! MediaStorage::exists($certificate->certificate_path)) {
            return response()->json(['message' => 'Sertifika dosyasi storage uzerinde bulunamadi.'], 404);
        }

        $extension = pathinfo($certificate->certificate_path, PATHINFO_EXTENSION);
        $filename = 'sertifika_' . strtolower($certificate->verification_code);

        return MediaStorage::disk()->download(
            $certificate->certificate_path,
            $filename . ($extension ? ".{$extension}" : '')
        );
    }
}
