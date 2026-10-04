<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToCompany;
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
            if ($document !== null) {
                $company = Company::find($document->company_id);
                if (! in_array($content->language_code, $company->enabled_languages, true)) {
                    throw ValidationException::withMessages(['language_code' => 'Language is not enabled for this company.']);
                }
                if ($content->is_authoritative !== ($content->language_code === 'ar')) {
                    throw ValidationException::withMessages(['is_authoritative' => 'Arabic is the authoritative language.']);
                }
            }
        });
        static::deleting(function (self $content): void {
            if (Document::withoutGlobalScopes()->find($content->document_id)?->is_locked) {
                throw new ImmutableRecordException('Locked document contents cannot be deleted.');
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
