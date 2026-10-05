<?php

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Concerns\MapsFormValidation;
use App\Filament\Admin\Resources\EmployeeResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditEmployee extends EditRecord
{
    use MapsFormValidation;

    protected static string $resource = EmployeeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (ValidationException $exception) {
            $this->rethrowFormValidation($exception);
        }
    }
}
