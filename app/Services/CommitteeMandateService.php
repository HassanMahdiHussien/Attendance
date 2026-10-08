<?php

namespace App\Services;

use App\Enums\UserRole;
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
        abort_unless($actor->can('create', CommitteeMandate::class), 403);

        return DB::transaction(function () use ($data, $actor): CommitteeMandate {
            $companyId = (int) ($data['company_id'] ?? app(TenantContext::class)->companyId());
            abort_unless($actor->primary_role === UserRole::PlatformAdmin || $actor->company_id === $companyId, 403);
            $latest = CommitteeMandate::query()->where('company_id', $companyId)->orderByDesc('effective_from')->lockForUpdate()->first();
            $effective = CarbonImmutable::parse($data['effective_from'], 'Asia/Baghdad')->startOfDay();
            if ($latest && $effective->toDateString() <= CarbonImmutable::parse($latest->effective_from)->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => __('messages.committee.mandate_effective_after_latest')]);
            }
            if ($latest) {
                $latest->update(['superseded_at' => $effective]);
            }
            $mandate = CommitteeMandate::create($data + ['company_id' => $companyId]);
            app(AuditLogger::class)->log('mandate.registered', $mandate, [
                'before' => ['previous_id' => $latest?->id],
                'after' => ['mandate_id' => $mandate->id, 'effective_from' => $effective->toDateString()],
            ], $actor);

            return $mandate;
        });
    }
}
