<?php

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Concerns\MapsFormValidation;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Services\Employees\EmployeeHistory;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateEmployee extends CreateRecord
{
    use MapsFormValidation;

    protected static string $resource = EmployeeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $effectiveFrom = $data['effective_from'] ?? today()->toDateString();
            $reason = $data['change_reason'] ?? null;
            $values = Arr::except($data, ['employee_code', 'effective_from', 'change_reason']);

            return app(EmployeeHistory::class)->create(
                Arr::only($data, ['employee_code', 'name', 'branch_id', 'hire_date', 'gender']),
                $values,
                $effectiveFrom,
                'manual',
                auth()->user(),
                $reason,
            );
        } catch (ValidationException $exception) {
            $this->rethrowFormValidation($exception);
        }
    }
}
