<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    /**
     * Authenticated user's certificates.
     */
    public function index(Request $request)
    {
        $certificates = Certificate::with(['project:id,name,slug', 'period:id,name'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('issued_at')
            ->get();

        return response()->json([
            'certificates' => CertificateResource::collection($certificates),
        ]);
    }

    /**
     * Public certificate verification.
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
