<?php

namespace App\Filament\Admin\Resources\CommitteeMandateResource\Pages;

use App\Filament\Admin\Concerns\MapsFormValidation;
use App\Filament\Admin\Resources\CommitteeMandateResource;
use App\Services\CommitteeMandateService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMandate extends CreateRecord
{
    use MapsFormValidation;

    protected static string $resource = CommitteeMandateResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['company_id'] = Filament::getTenant()->id;

        try {
            return app(CommitteeMandateService::class)->register($data, auth()->user());
        } catch (ValidationException $exception) {
            $this->rethrowFormValidation($exception);
        }
    }
}
