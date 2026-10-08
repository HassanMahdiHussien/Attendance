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
                throw new IssuanceBlocked("القالب باللغة {$language} للنوع {$type} غير موجود.");
            }
            $blocks = require $file;
            $options = ['show_footer' => true, 'header_mode' => 'logo_text'];
        }
        if ($type === 'recommendations' && blank($blocks['outcome_sentence'] ?? null)) {
            $defaults = app_path("Documents/Defaults/recommendations.{$language}.php");
            if (is_file($defaults)) {
                $blocks['outcome_sentence'] = (require $defaults)['outcome_sentence'];
            }
        }
        app(TemplateValidator::class)->validate($type, $blocks);
        app(TemplateValidator::class)->validateOptions($options);

        return ['version' => $row?->version ?? 0, 'id' => $row?->id, 'blocks' => $blocks, 'options' => $options];
    }
}
