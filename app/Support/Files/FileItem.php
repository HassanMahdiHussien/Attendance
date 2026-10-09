<?php

namespace App\Support\Files;

use App\Models\Attachment;
use App\Models\Document;
use App\Support\Format\Ui;

class FileItem
{
    public static function forAttachment(Attachment $attachment): array
    {
        $kind = match ($attachment->mime_type) {
            'image/png', 'image/jpeg' => 'image',
            'application/pdf' => 'pdf',
            default => 'other',
        };
        $download = route('attachments.download', $attachment);

        return [
            'name' => $attachment->original_name,
            'kind' => $kind,
            'view_url' => $kind === 'other' ? null : $download.'?preview=1',
            'download_url' => $download,
        ];
    }

    public static function forDocument(Document $document): array
    {
        $ready = $document->is_locked && $document->pdf_status === 'ready';
        $type = match ($document->document_type->value) {
            'defense_letter' => __('Defense request letter'),
            'recommendations' => __('Committee recommendations'),
            'decision_letter' => __('Decision letter'),
            default => __('Document'),
        };

        return [
            'name' => $type.' · '.__('Version').' '.Ui::number($document->version),
            'kind' => $ready ? 'pdf' : ($document->is_locked ? 'other' : 'html'),
            'view_url' => $ready ? route('documents.view', $document) : ($document->is_locked ? null : route('documents.preview', $document)),
            'download_url' => $ready ? route('documents.pdf', $document) : null,
        ];
    }
}
