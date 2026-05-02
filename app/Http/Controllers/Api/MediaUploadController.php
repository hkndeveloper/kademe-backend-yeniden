<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        foreach (['projects.content.update', 'projects.gallery.update'] as $permission) {
            if ($this->permissionResolver->projectIdsForPermission($user, $permission) !== []) {
                return true;
            }
        }

        return false;
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->canUpload($request), 403, 'Bu islem icin yetkiniz bulunmuyor.');

        $validated = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'folder' => 'nullable|string|max:100',
        ]);

        $folder = Str::slug($validated['folder'] ?? 'general');
        $file = $validated['file'];
        $filename = Str::uuid()->toString() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs("kademe-media/{$folder}", $filename, 'public');

        return response()->json([
            'message' => 'Dosya yuklendi.',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
