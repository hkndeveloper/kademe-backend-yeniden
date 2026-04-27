<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use Illuminate\Http\Request;

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
}
