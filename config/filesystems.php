<?php

use App\Services\FurimaDeckR2UploadCommandOptions;

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

        'furimadeck_r2_dev' => [
            'driver' => 's3',
            'key' => env('PRODUCT_IMAGES_ACCESS_KEY_ID'),
            'secret' => env('PRODUCT_IMAGES_SECRET_ACCESS_KEY'),
            'region' => env('PRODUCT_IMAGES_REGION', 'auto'),
            'bucket' => env('PRODUCT_IMAGES_BUCKET'),
            'endpoint' => env('PRODUCT_IMAGES_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'options' => [
                'before_upload' => [FurimaDeckR2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                'mup_threshold' => PHP_INT_MAX,
            ],
            'throw' => true,
            'report' => false,
        ],

        'furimadeck_r2' => [
            'driver' => 's3',
            'key' => env('FURIMADECK_R2_ACCESS_KEY_ID'),
            'secret' => env('FURIMADECK_R2_SECRET_ACCESS_KEY'),
            'region' => env('FURIMADECK_R2_REGION', 'auto'),
            'bucket' => env('FURIMADECK_R2_BUCKET'),
            'endpoint' => env('FURIMADECK_R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'options' => [
                'before_upload' => [FurimaDeckR2UploadCommandOptions::class, 'removeUnsupportedAcl'],
                'mup_threshold' => PHP_INT_MAX,
            ],
            'throw' => true,
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
