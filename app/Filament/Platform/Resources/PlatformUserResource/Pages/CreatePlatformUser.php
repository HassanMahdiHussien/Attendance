<?php

namespace App\Filament\Platform\Resources\PlatformUserResource\Pages;

use App\Enums\UserRole;
use App\Filament\Platform\Resources\PlatformUserResource;
use App\Services\UserAccountService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlatformUser extends CreateRecord
{
    protected static string $resource = PlatformUserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(in_array($data['primary_role'], [
            UserRole::PlatformAdmin->value, UserRole::ExternalLegalReviewer->value, UserRole::HrPartnerStaff->value,
        ], true), 403);
        $data['company_id'] = null;
        $data['law_firm_partner_id'] = $data['primary_role'] === UserRole::ExternalLegalReviewer->value ? ($data['law_firm_partner_id'] ?? null) : null;
        $data['hr_partner_id'] = $data['primary_role'] === UserRole::HrPartnerStaff->value ? ($data['hr_partner_id'] ?? null) : null;

        return app(UserAccountService::class)->create($data, auth()->user());
    }
}
