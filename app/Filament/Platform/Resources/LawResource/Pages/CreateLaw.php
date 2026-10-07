<?php

namespace App\Filament\Platform\Resources\LawResource\Pages;

use App\Filament\Platform\Resources\LawResource;
use App\LegalLibrary\LawManagement;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateLaw extends CreateRecord
{
    protected static string $resource = LawResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $jurisdictions = $data['jurisdiction_values'] ?? [];
        unset($data['jurisdiction_values']);
        $law = app(LawManagement::class)->create(auth()->user(), $data);
        app(LawManagement::class)->setJurisdictions($law, auth()->user(), $jurisdictions);

        return $law;
    }
}
