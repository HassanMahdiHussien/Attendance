<?php

namespace App\Ai;

use App\LegalLibrary\PdfPages;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ScanBlocks
{
    public function __construct(private PdfPages $pages) {}

    public function for(Attachment $attachment): array
    {
        $path = Storage::disk($attachment->disk)->path($attachment->path);
        $size = filesize($path);
        if ($size === false || $size > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['scan' => __('messages.ai.scan_too_large')]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime === 'application/pdf') {
            if ($this->pages->count($path) > 20) {
                throw ValidationException::withMessages(['scan' => __('messages.ai.scan_too_many_pages')]);
            }
            $bytes = file_get_contents($path);

            return ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($bytes)]];
        }
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw ValidationException::withMessages(['scan' => __('messages.ai.scan_type_invalid')]);
        }
        try {
            $image = $mime === 'image/png' ? imagecreatefrompng($path) : imagecreatefromjpeg($path);
        } catch (\ErrorException) {
            // GD raises a warning (an exception under Laravel) for a corrupt file before returning false.
            $image = false;
        }
        if ($image === false) {
            throw ValidationException::withMessages(['scan' => __('messages.ai.scan_image_unreadable')]);
        }
        $edge = max(imagesx($image), imagesy($image));
        if ($edge > 2000) {
            $scale = 2000 / $edge;
            $smaller = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
            imagedestroy($image);
            $image = $smaller;
        }
        ob_start();
        if ($mime === 'image/png') {
            imagepng($image);
        } else {
            imagejpeg($image, null, 90);
        }
        $bytes = ob_get_clean();
        imagedestroy($image);

        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($bytes)]];
    }
}
