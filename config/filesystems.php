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

        // Cloudflare R2 — S3-compatible object storage (league/flysystem-
        // aws-s3-v3, same driver the stock 's3' disk above uses; R2 is
        // just a different S3-compatible endpoint/credential set, not a
        // different Laravel driver). Never used unless something
        // explicitly requests Storage::disk('r2') — the app's default
        // disk (FILESYSTEM_DISK) and every existing upload call site
        // (Storage::disk('public')) are untouched, so nothing silently
        // starts writing to R2 just because these credentials exist.
        //
        // R2_ENDPOINT is the account-level S3 API endpoint (https://
        // {account_id}.r2.cloudflarestorage.com — same shape for every
        // bucket in the account, the bucket itself is selected by
        // R2_BUCKET, not by the endpoint). region 'auto' is R2's own
        // documented value — R2 doesn't have AWS-style regions, but the
        // S3 client still requires a non-empty value.
        // use_path_style_endpoint=true is required for R2 (it does not
        // support virtual-hosted-style {bucket}.{endpoint} addressing).
        // R2_URL is the public base URL files are actually served from —
        // a bucket's r2.dev subdomain, or a custom domain if one is
        // mapped to the bucket — used for url()/temporaryUrl() generation
        // separately from the S3 API endpoint above.
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => env('R2_REGION', 'auto'),
            'bucket' => env('R2_BUCKET'),
            'url' => env('R2_URL'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'public',
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
