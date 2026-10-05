<?php

namespace App\Documents;

use App\Exceptions\IssuanceBlocked;
use App\Models\DocumentTemplate;

class TemplateResolver
{
    public function resolve(int $companyId, string $type, string $language): array
    {
        $row = DocumentTemplate::query()->where('company_id', $companyId)->where('document_type', $type)
            ->where('language_code', $language)->orderByDesc('version')->first();
        if ($row) {
            $blocks = $row->blocks;
            $options = $row->options;
        } else {
            $file = app_path("Documents/Defaults/{$type}.{$language}.php");
            if (! is_file($file)) {
                throw new IssuanceBlocked("Missing {$language} template for {$type}.");
            }
            $blocks = require $file;
            $options = ['show_footer' => true, 'header_mode' => 'logo_text'];
        }
        app(TemplateValidator::class)->validate($type, $blocks);
        app(TemplateValidator::class)->validateOptions($options);

        return ['version' => $row?->version ?? 0, 'id' => $row?->id, 'blocks' => $blocks, 'options' => $options];
    }
}
