<?php

namespace App\Support;

use App\Models\Application;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationFileStorage
{
    private const DISK = 'application_private';

    public static function putFile(UploadedFile $file): string
    {
        self::assertWritableConfiguration();

        try {
            $path = $file->store('application-files', self::DISK);
        } catch (\Throwable) {
            $path = false;
        }

        if (! is_string($path) || $path === '') {
            throw new HttpResponseException(response()->json([
                'message' => 'Başvuru dosyası güvenli alana yüklenemedi. Lütfen daha sonra tekrar deneyin.',
            ], 503));
        }

        return $path;
    }

    public static function delete(string $path): bool
    {
        try {
            return Storage::disk(self::DISK)->delete($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function download(array $file, string $fallbackName): StreamedResponse
    {
        $private = ($file['storage'] ?? null) === self::DISK;
        $path = $private
            ? ($file['path'] ?? null)
            : MediaStorage::normalizeToStorageKey($file['path'] ?? null);

        abort_unless(
            is_string($path)
                && str_starts_with($path, 'application-files/')
                && ! str_contains($path, '..')
                && ! str_contains($path, '\\'),
            404,
            'Başvuru dosyası bulunamadı.',
        );

        $disk = $private ? Storage::disk(self::DISK) : MediaStorage::disk();
        abort_unless($disk->exists($path), 404, 'Başvuru dosyası depolamada bulunamadı.');

        $name = basename((string) ($file['original_name'] ?? $fallbackName));

        return $disk->download($path, $name !== '' ? $name : $fallbackName);
    }

    public static function fileForField(Application $application, string $field): ?array
    {
        $definition = collect($application->form_fields_snapshot ?? $application->form?->fields ?? [])->first(
            fn (array $item) => ($item['id'] ?? $item['key'] ?? null) === $field,
        );
        if (($definition['type'] ?? null) !== 'file') {
            return null;
        }

        $value = ($application->form_data ?? [])[$field] ?? null;

        return is_array($value) && ! empty($value['path']) ? $value : null;
    }

    private static function assertWritableConfiguration(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $disk = config('filesystems.disks.'.self::DISK, []);
        $privateBucket = (string) ($disk['bucket'] ?? '');
        $publicBucket = (string) config('filesystems.disks.'.config('filesystems.media_disk').'.bucket', '');

        if (($disk['driver'] ?? null) !== 's3' || $privateBucket === '' || ($publicBucket !== '' && $privateBucket === $publicBucket)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Başvuru dosyaları için ayrı ve özel bir saklama alanı ayarlanmalıdır.',
            ], 503));
        }
    }
}
