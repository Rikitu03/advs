<?php

return [
    'paths' => [
        resource_path('views'),
    ],

    'compiled' => env('APP_ENV') === 'testing'
        ? storage_path('framework/views_testing')
        : env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views')) ?: storage_path('framework/views')),
];
