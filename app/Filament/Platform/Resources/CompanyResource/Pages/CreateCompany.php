<?php

namespace App\Filament\Platform\Resources\CompanyResource\Pages;

use App\Filament\Platform\Resources\CompanyResource;
use App\Services\CompanyOnboardingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $admin = [
            'name' => $data['first_admin_name'],
            'email' => $data['first_admin_email'],
            'password' => $data['first_admin_password'],
        ];
        $kurdish = (bool) ($data['kurdish_enabled'] ?? false);
        unset($data['first_admin_name'], $data['first_admin_email'], $data['first_admin_password']);
        unset($data['kurdish_enabled']);
        $data['enabled_languages'] = ['ar', 'en', ...($kurdish ? ['ku'] : [])];

        return app(CompanyOnboardingService::class)->create($data, $admin, auth()->user());
    }
}
