<?php

namespace App\Filament\Platform\Resources\HrPartnerResource\Pages;

use App\Filament\Platform\Resources\HrPartnerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHrPartners extends ListRecords
{
    protected static string $resource = HrPartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
