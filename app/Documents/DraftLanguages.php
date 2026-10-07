<?php

namespace App\Documents;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\DocumentTemplate;

class DraftLanguages
{
    public function for(Company $company, DocumentType $type): array
    {
        $languages = ['ar', 'en'];
        if (in_array('ku', $company->enabled_languages, true)
            && DocumentTemplate::query()->where('company_id', $company->id)
                ->where('document_type', $type->value)->where('language_code', 'ku')->exists()) {
            $languages[] = 'ku';
        }

        return $languages;
    }
}
