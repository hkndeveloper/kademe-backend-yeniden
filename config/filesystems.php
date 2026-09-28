<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    'media_disk' => env('MEDIA_DISK', 'public'),

    'direct_media_downloads' => env('MEDIA_DIRECT_DOWNLOADS', false),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'application_private' => [
            'driver' => env('APPLICATION_FILES_DRIVER', 'local'),
            'root' => env('APPLICATION_FILES_DRIVER', 'local') === 'local' ? storage_path('app/private') : '',
            'key' => env('APPLICATION_FILES_ACCESS_KEY_ID', env('R2_ACCESS_KEY_ID')),
            'secret' => env('APPLICATION_FILES_SECRET_ACCESS_KEY', env('R2_SECRET_ACCESS_KEY')),
            'region' => env('APPLICATION_FILES_REGION', env('R2_DEFAULT_REGION', 'auto')),
            'bucket' => env('APPLICATION_FILES_BUCKET'),
            'endpoint' => env('APPLICATION_FILES_ENDPOINT', env('R2_ENDPOINT')),
            'use_path_style_endpoint' => env('APPLICATION_FILES_PATH_STYLE', true),
            'visibility' => 'private',
            'throw' => true,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_DEFAULT_REGION', 'auto'),
            'bucket' => env('R2_BUCKET_NAME'),
            'url' => env('R2_PUBLIC_URL', 'https://img.hakankekec.me'),
            'stable_url' => env('R2_STABLE_PUBLIC_URL', env('MEDIA_PUBLIC_URL', 'https://img.hakankekec.me')),
            'legacy_urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('R2_LEGACY_PUBLIC_URLS', ''))))),
            'endpoint' => env('R2_ENDPOINT', env('R2_ACCOUNT_ID') ? 'https://' . env('R2_ACCOUNT_ID') . '.r2.cloudflarestorage.com' : null),
            'use_path_style_endpoint' => env('R2_USE_PATH_STYLE_ENDPOINT', true),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
