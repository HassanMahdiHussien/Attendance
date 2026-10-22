<?php

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Concerns\MapsFormValidation;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Services\Employees\EmployeeHistory;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditEmployee extends EditRecord
{
    use MapsFormValidation;

    protected static string $resource = EmployeeResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(EmployeeHistory::class)->record(
                $record,
                Arr::except($data, ['employee_code', 'effective_from', 'change_reason']),
                $data['effective_from'] ?? today()->toDateString(),
                'manual',
                auth()->user(),
                $data['change_reason'] ?? null,
            );

            return $record->fresh();
        } catch (ValidationException $exception) {
            $this->rethrowFormValidation($exception);
        }
    }
}
