<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaStorage
{
    public static function diskName(): string
    {
        return config('filesystems.media_disk', config('filesystems.default', 'public'));
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function putFile(string $directory, UploadedFile $file): string
    {
        return $file->store($directory, self::diskName());
    }

    public static function putFileAs(string $directory, UploadedFile $file, string $name): string
    {
        return $file->storeAs($directory, $name, self::diskName());
    }

    public static function delete(?string $path): bool
    {
        if (! $path || self::isUrl($path)) {
            return false;
        }

        return self::disk()->delete($path);
    }

    public static function exists(?string $path): bool
    {
        if (! $path || self::isUrl($path)) {
            return false;
        }

        return self::disk()->exists($path);
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (self::isUrl($path)) {
            return $path;
        }

        $publicBase = rtrim((string) config('filesystems.disks.' . self::diskName() . '.url'), '/');
        if ($publicBase !== '') {
            return $publicBase . '/' . ltrim($path, '/');
        }

        return self::disk()->url($path);
    }

    private static function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }
}
