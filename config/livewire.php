<?php

// Only the upload block is overridden; Livewire merges its other defaults.
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // The temporary endpoint must accept files up to the attendance import's validated limit.
        'rules' => ['required', 'file', 'max:102400'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 15,
        'cleanup' => true,
    ],
];
