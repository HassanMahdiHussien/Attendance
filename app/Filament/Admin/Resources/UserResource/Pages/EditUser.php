<?php

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Concerns\MapsFormValidation;
use App\Filament\Admin\Resources\UserResource;
use App\Services\UserAccountService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    use MapsFormValidation;

    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(array_key_exists($data['primary_role'], UserResource::allowedRoles()), 403);
        $data['company_id'] = Filament::getTenant()->id;

        try {
            return app(UserAccountService::class)->update($record, $data, auth()->user());
        } catch (ValidationException $exception) {
            $this->rethrowFormValidation($exception);
        }
    }
}
