<?php

// Only the upload block is overridden; Livewire merges its other defaults. Report evidence allows videos up to
// 50 MB (per-type limits are enforced by AttachmentService), so the temporary upload cap must allow that size,
// and a 50 MB upload from a phone on site needs more than Livewire's default five minutes.
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:51200'],
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
