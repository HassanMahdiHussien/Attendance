<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeImportBatch extends Model
{
    use AppendOnly, BelongsToCompany, HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    protected array $companyUserKeys = ['uploaded_by'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'mapping' => 'array', 'counts' => 'array', 'summary' => 'array', 'created_at' => 'datetime'];
    }

    /** The earlier import of this exact file, unless it held rows back: those rows may be imported later. */
    public static function completeImportOf(int $companyId, string $hash): ?self
    {
        return self::query()->where('company_id', $companyId)->where('file_sha256', $hash)->orderBy('id')->get()
            ->first(fn (self $batch): bool => (int) ($batch->counts['skipped'] ?? 0) === 0);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
