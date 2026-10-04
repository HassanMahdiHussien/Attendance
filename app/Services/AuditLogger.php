<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(string $action, ?Model $entity = null, array $metadata = [], ?User $actor = null): AuditLog
    {
        $actor ??= Auth::user();

        return AuditLog::create([
            'company_id' => $entity instanceof Company ? $entity->id
                : ($entity?->getAttribute('company_id') ?? app(TenantContext::class)->companyId()),
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
