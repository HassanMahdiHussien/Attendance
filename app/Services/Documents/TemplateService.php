<?php

namespace App\Services\Documents;

use App\Documents\TemplateValidator;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TemplateService
{
    public function __construct(private TemplateValidator $validator, private AuditLogger $audit) {}

    public function saveVersion(Company $company, string $type, string $language, array $blocks, array $options, User $actor): DocumentTemplate
    {
        $this->authorize($company, $actor);
        if (! DocumentType::tryFrom($type) || ! in_array($language, $company->enabled_languages, true)) {
            throw ValidationException::withMessages(['language' => 'Choose an enabled language and document type.']);
        }
        $this->validator->validate($type, $blocks);
        $this->validator->validateOptions($options);

        return DB::transaction(function () use ($company, $type, $language, $blocks, $options, $actor): DocumentTemplate {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $previous = DocumentTemplate::query()->where('company_id', $company->id)
                ->where('document_type', $type)->where('language_code', $language)->orderByDesc('version')->first();
            $version = DocumentTemplate::create([
                'company_id' => $company->id, 'document_type' => $type, 'language_code' => $language,
                'version' => ($previous?->version ?? 0) + 1, 'blocks' => $blocks, 'options' => $options,
                'created_by' => $actor->id, 'created_at' => now(),
            ]);
            $this->audit->log('document_template.version_created', $version, [
                'before' => $previous?->only(['version', 'blocks', 'options']),
                'after' => $version->only(['version', 'blocks', 'options']),
            ], $actor);

            return $version;
        });
    }

    private function authorize(Company $company, User $actor): void
    {
        abort_unless(in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::PlatformAdmin], true)
            && ($actor->primary_role === UserRole::PlatformAdmin || (int) $actor->company_id === (int) $company->id), 403);
    }
}
