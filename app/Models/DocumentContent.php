<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Cases\RecommendationsEditor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class DocumentContent extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'document_contents';

    protected $guarded = [];

    protected array $companyParents = ['document'];

    protected static function booted(): void
    {
        static::saving(function (self $content): void {
            $document = Document::withoutGlobalScopes()->find($content->document_id);
            if ($document?->is_locked) {
                throw new ImmutableRecordException('Locked document contents cannot be changed.');
            }
            if ($document?->document_type === DocumentType::Recommendations && ! RecommendationsEditor::isWriting()) {
                throw new ImmutableRecordException('Recommendations drafts must be edited through RecommendationsEditor.');
            }
            if ($document !== null) {
                $company = Company::find($document->company_id);
                if (! in_array($content->language_code, $company->enabled_languages, true)) {
                    throw ValidationException::withMessages(['language_code' => __('messages.document.language_not_enabled')]);
                }
                if ($content->is_authoritative !== ($content->language_code === 'ar')) {
                    throw ValidationException::withMessages(['is_authoritative' => __('messages.document.arabic_authoritative')]);
                }
            }
        });
        static::deleting(function (self $content): void {
            $document = Document::withoutGlobalScopes()->find($content->document_id);
            if ($document?->is_locked) {
                throw new ImmutableRecordException('Locked document contents cannot be deleted.');
            }
            if ($document?->document_type === DocumentType::Recommendations && ! RecommendationsEditor::isWriting()) {
                throw new ImmutableRecordException('Recommendations drafts must be edited through RecommendationsEditor.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_authoritative' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
