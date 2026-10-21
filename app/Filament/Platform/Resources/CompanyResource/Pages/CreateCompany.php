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
        $firstHrUser = [
            'name' => $data['first_hr_user_name'],
            'email' => $data['first_hr_user_email'],
            'password' => $data['first_hr_user_password'],
        ];
        $kurdish = (bool) ($data['kurdish_enabled'] ?? false);
        unset($data['first_hr_user_name'], $data['first_hr_user_email'], $data['first_hr_user_password']);
        unset($data['kurdish_enabled']);
        $data['enabled_languages'] = ['ar', 'en', ...($kurdish ? ['ku'] : [])];

        return app(CompanyOnboardingService::class)->create($data, $firstHrUser, auth()->user());
    }
}
