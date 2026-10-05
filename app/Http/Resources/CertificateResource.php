<?php

namespace App\Http\Resources;

use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'issuer' => $this->issuer,
            'source' => $this->source,
            'included_in_cv' => (bool) $this->included_in_cv,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'verification_code' => $this->source === 'student_upload' ? null : $this->verification_code,
            'issued_at' => $this->issued_at,
            'certificate_path' => $this->certificate_path,
            'file_url' => $this->source !== 'student_upload' && MediaStorage::directDownloadsEnabled() ? MediaStorage::url($this->certificate_path) : null,
            'download_url' => $this->certificate_path
                ? ($this->source === 'student_upload'
                    ? url("/api/certificates/mine/{$this->id}/download")
                    : url("/api/certificates/{$this->verification_code}/download"))
                : null,
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project?->id,
                    'name' => $this->project?->name,
                    'slug' => $this->project?->slug,
                ];
            }),
            'period' => $this->whenLoaded('period', function () {
                return [
                    'id' => $this->period?->id,
                    'name' => $this->period?->name,
                ];
            }),
        ];
    }
}
