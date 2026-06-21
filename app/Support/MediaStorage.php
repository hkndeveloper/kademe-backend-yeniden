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

    /**
     * Veritabanında veya istemcide saklanmış tam URL'yi, disk üzerindeki göreli anahtara çevirir.
     * (Eski kayıtlar tam URL tutuyorsa silme/varlık kontrolü çalışsın diye.)
     */
    public static function normalizeToStorageKey(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $stored = trim($stored);
        if (! self::isUrl($stored)) {
            return $stored;
        }
        foreach (self::configuredPublicBases() as $publicBase) {
            if (str_starts_with($stored, $publicBase . '/')) {
                return ltrim(substr($stored, strlen($publicBase)), '/');
            }
        }

        $pathPart = parse_url($stored, PHP_URL_PATH);
        if (! is_string($pathPart) || $pathPart === '' || $pathPart === '/') {
            return null;
        }
        $pathPart = ltrim($pathPart, '/');
        if (str_starts_with($pathPart, 'storage/')) {
            return substr($pathPart, strlen('storage/'));
        }

        return $pathPart;
    }

    public static function delete(?string $path): bool
    {
        $key = self::normalizeToStorageKey($path);
        if (! $key) {
            return false;
        }

        try {
            return self::disk()->delete($key);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function exists(?string $path): bool
    {
        $key = self::normalizeToStorageKey($path);
        if (! $key) {
            return false;
        }

        try {
            return self::disk()->exists($key);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function mimeType(?string $path): ?string
    {
        $key = self::normalizeToStorageKey($path);
        if (! $key) {
            return null;
        }

        try {
            $mimeType = self::disk()->mimeType($key);

            return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $publicBase = self::publicBaseUrl();

        if (self::isUrl($path)) {
            $key = self::storageKeyFromKnownPublicUrl($path);
            if ($publicBase !== '' && $key) {
                return $publicBase . '/' . ltrim($key, '/');
            }

            return $path;
        }

        if ($publicBase !== '') {
            return $publicBase . '/' . ltrim($path, '/');
        }

        if (self::usesTemporaryUrls()) {
            try {
                return self::disk()->temporaryUrl(ltrim($path, '/'), now()->addMinutes(30));
            } catch (\Throwable) {
                // Yerel diskler veya temporaryUrl desteklemeyen adapter'lar normal URL akışına düşer.
            }
        }

        return self::disk()->url($path);
    }

    public static function publicUrlConfigured(): bool
    {
        return self::publicBaseUrl() !== '';
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

    public static function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    private static function usesTemporaryUrls(): bool
    {
        $diskConfig = config('filesystems.disks.' . self::diskName(), []);

        return is_array($diskConfig)
            && ($diskConfig['driver'] ?? null) === 's3'
            && self::publicBaseUrl() === '';
    }

    private static function publicBaseUrl(): string
    {
        return rtrim((string) config('filesystems.disks.' . self::diskName() . '.url'), '/');
    }

    private static function storageKeyFromKnownPublicUrl(string $url): ?string
    {
        foreach (self::configuredPublicBases() as $publicBase) {
            if (str_starts_with($url, $publicBase . '/')) {
                return ltrim(substr($url, strlen($publicBase)), '/');
            }
        }

        return null;
    }

    /**
     * Current and legacy public bases. This lets old DB rows that stored a full
     * r2.dev URL be served from the active custom domain without rewriting data.
     *
     * @return array<int, string>
     */
    private static function configuredPublicBases(): array
    {
        $diskConfig = config('filesystems.disks.' . self::diskName(), []);
        $bases = [self::publicBaseUrl()];

        if (is_array($diskConfig)) {
            $legacyUrls = $diskConfig['legacy_urls'] ?? [];
            if (is_string($legacyUrls)) {
                $legacyUrls = explode(',', $legacyUrls);
            }
            if (is_array($legacyUrls)) {
                $bases = array_merge($bases, $legacyUrls);
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($base) => rtrim((string) $base, '/'),
            $bases,
        ))));
    }
}
