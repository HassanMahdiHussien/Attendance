<?php

namespace App\Filament\Platform\Resources\CompanyResource\Pages;

use App\Filament\Platform\Resources\CompanyResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['kurdish_enabled'] = in_array('ku', $data['enabled_languages'] ?? [], true);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $kurdish = (bool) ($data['kurdish_enabled'] ?? false);
        unset($data['kurdish_enabled'], $data['first_admin_name'], $data['first_admin_email'], $data['first_admin_password']);
        $data['enabled_languages'] = ['ar', 'en', ...($kurdish ? ['ku'] : [])];
        $record->update($data);

        return $record;
    }
}
