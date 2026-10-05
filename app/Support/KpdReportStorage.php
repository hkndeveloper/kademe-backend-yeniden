<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KpdReportStorage
{
    private const PRIVATE_PREFIX = 'private:';

    public static function put(UploadedFile $file): string
    {
        $path = $file->store('kpd-reports', 'application_private');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('KPD raporu özel depoya kaydedilemedi.');
        }

        return self::PRIVATE_PREFIX.$path;
    }

    public static function isPrivate(?string $path): bool
    {
        return is_string($path) && str_starts_with($path, self::PRIVATE_PREFIX);
    }

    public static function key(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return self::isPrivate($path)
            ? substr($path, strlen(self::PRIVATE_PREFIX))
            : MediaStorage::normalizeToStorageKey($path);
    }

    public static function exists(?string $path): bool
    {
        $key = self::key($path);

        if ($key === null || $key === '') {
            return false;
        }

        try {
            return self::disk($path)->exists($key);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function download(string $path, string $filename): StreamedResponse
    {
        return self::disk($path)->download(self::key($path), $filename);
    }

    public static function delete(?string $path): bool
    {
        $key = self::key($path);

        if ($key === null || $key === '') {
            return false;
        }

        try {
            return self::disk($path)->delete($key);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function privatePath(string $key): string
    {
        return self::PRIVATE_PREFIX.$key;
    }

    public static function disk(?string $path): Filesystem
    {
        return self::isPrivate($path) ? Storage::disk('application_private') : MediaStorage::disk();
    }
}
