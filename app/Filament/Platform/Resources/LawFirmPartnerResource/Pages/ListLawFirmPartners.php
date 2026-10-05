<?php

namespace App\Filament\Platform\Resources\LawFirmPartnerResource\Pages;

use App\Filament\Platform\Resources\LawFirmPartnerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLawFirmPartners extends ListRecords
{
    protected static string $resource = LawFirmPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
