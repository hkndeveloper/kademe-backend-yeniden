<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionResolver;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @group Files & Exports
 */
class MediaUploadController extends Controller
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver
    ) {
    }

    private function canUpload(Request $request): bool
    {
        $user = $request->user();
        $globalPerms = [
            'content.blog.create',
            'content.blog.update',
            'content.faq.create',
            'content.faq.update',
            'settings.update',
            'content.site_settings.update',
        ];

        foreach ($globalPerms as $permission) {
            if ($this->permissionResolver->hasGlobalScope($user, $permission)) {
                return true;
            }
        }

        foreach ([
            'projects.content.update',
            'projects.gallery.update',
            'projects.internships.manage',
            'projects.mentors.manage',
            'projects.eurodesk.manage',
            'projects.rewards.manage',
            'programs.update',
            'programs.media.upload',
        ] as $permission) {
            if ($this->permissionResolver->projectIdsForPermission($user, $permission) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upload a media file.
     *
     * Panel/admin endpoint exposed under `/admin/media/upload` and `/panel/media/upload`. Access is granted when the user has global scope for one of the content/settings permissions (`content.blog.create`, `content.blog.update`, `content.faq.create`, `content.faq.update`, `settings.update`, `content.site_settings.update`) or at least one scoped project/program media permission (`projects.content.update`, `projects.gallery.update`, `projects.internships.manage`, `projects.mentors.manage`, `projects.eurodesk.manage`, `projects.rewards.manage`, `programs.update`, `programs.media.upload`).
     *
     * Send as `multipart/form-data`. The optional `folder` is slugged and files are stored under `kademe-media/{folder}` with a UUID filename. Returns both storage path and resolved URL.
     *
     * @group Files & Exports
     * @authenticated
     *
     * @bodyParam file file required Upload file; jpg, jpeg, png, webp, pdf, doc, docx, max 20MB.
     * @bodyParam folder string Optional logical folder name. Example: homepage-hero
     * @response 200 {"message":"Dosya yuklendi.","path":"kademe-media/homepage-hero/uuid.png","url":"https://storage.example.com/kademe-media/homepage-hero/uuid.png"}
     * @response 403 {"message":"Bu islem icin yetkiniz bulunmuyor."}
     * @response 422 {"message":"The file field is required."}
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->canUpload($request), 403, 'Bu islem icin yetkiniz bulunmuyor.');

        $validated = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:20480',
            'folder' => 'nullable|string|max:100',
        ]);

        $folder = Str::slug($validated['folder'] ?? 'general');
        $file = $validated['file'];
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $path = MediaStorage::putFileAs("kademe-media/{$folder}", $file, $filename);

        return response()->json([
            'message' => 'Dosya yuklendi.',
            'path' => $path,
            'url' => MediaStorage::url($path),
        ]);
    }
}
