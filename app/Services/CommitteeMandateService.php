<?php

namespace App\Services;

use App\Models\CommitteeMandate;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommitteeMandateService
{
    public function register(array $data, User $actor): CommitteeMandate
    {
        return DB::transaction(function () use ($data, $actor): CommitteeMandate {
            $companyId = (int) ($data['company_id'] ?? app(TenantContext::class)->companyId());
            $latest = CommitteeMandate::query()->where('company_id', $companyId)->orderByDesc('effective_from')->lockForUpdate()->first();
            $effective = CarbonImmutable::parse($data['effective_from'], 'Asia/Baghdad')->startOfDay();
            if ($latest && $effective->toDateString() <= CarbonImmutable::parse($latest->effective_from)->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'The effective date must be later than the latest order.']);
            }
            if ($latest) {
                $latest->update(['superseded_at' => $effective]);
            }
            $mandate = CommitteeMandate::create($data + ['company_id' => $companyId]);
            app(AuditLogger::class)->log('mandate.registered', $mandate, ['previous_id' => $latest?->id, 'effective_from' => $effective->toDateString()], $actor);

            return $mandate;
        });
    }
}
