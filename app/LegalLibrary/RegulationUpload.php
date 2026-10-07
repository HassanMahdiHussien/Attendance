<?php

namespace App\LegalLibrary;

use App\Enums\ImportMode;
use App\Enums\LawKind;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Law;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class RegulationUpload
{
    public function __construct(private LawImportService $imports, private AuditLogger $audit) {}

    public function upload(Company $company, User $actor, UploadedFile $file, array $meta): Law
    {
        abort_unless($actor->is_active && (($actor->primary_role === UserRole::CompanyAdmin
            && (int) $actor->company_id === (int) $company->id)
            || $actor->primary_role === UserRole::PlatformAdmin), 403);

        return app(TenantContext::class)->runForCompany($company, fn (): Law => DB::transaction(function () use ($company, $actor, $file, $meta): Law {
            $law = Law::create(['company_id' => $company->id, 'kind' => LawKind::InternalRegulation,
                'category' => 'regulation', 'name' => $meta['name'],
                'number' => $meta['number'] ?? null, 'year' => $meta['year'] ?? null,
                'effective_from' => $meta['effective_from'] ?? null,
                'source_file_path' => 'pending', 'approval_status' => 'pending']);
            $this->audit->log('law.created', $law, $meta, $actor);
            $this->imports->start($law, $actor, $file, $meta['page_from'] ?? null,
                $meta['page_to'] ?? null, ImportMode::from($meta['mode'] ?? 'auto'));
            $company->update(['has_internal_regulation' => true]);

            return $law;
        }));
    }
}
