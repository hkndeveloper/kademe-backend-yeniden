<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        try {
            return self::ensureStoredPath($file->store($directory, self::diskName()));
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable) {
            self::throwStorageError();
        }
    }

    public static function putFileAs(string $directory, UploadedFile $file, string $name): string
    {
        try {
            return self::ensureStoredPath($file->storeAs($directory, $name, self::diskName()));
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable) {
            self::throwStorageError();
        }
    }

    public static function delete(?string $path): bool
    {
        if (! $path || self::isUrl($path)) {
            return false;
        }

        try {
            return self::disk()->delete($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function exists(?string $path): bool
    {
        if (! $path || self::isUrl($path)) {
            return false;
        }

        try {
            return self::disk()->exists($path);
        } catch (\Throwable) {
            return false;
        }
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

    public static function publicUrlConfigured(): bool
    {
        return rtrim((string) config('filesystems.disks.' . self::diskName() . '.url'), '/') !== '';
    }

    public static function directDownloadsEnabled(): bool
    {
        return (bool) config('filesystems.direct_media_downloads', false);
    }

    private static function ensureStoredPath(mixed $path): string
    {
        if (is_string($path) && $path !== '') {
            return $path;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Dosya yuklenemedi. Storage/R2 ayarlarini kontrol edin.',
        ], 503));
    }

    private static function throwStorageError(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Dosya yuklenemedi. Storage/R2 baglantisini ve Railway env ayarlarini kontrol edin.',
        ], 503));
    }

    private static function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }
}
