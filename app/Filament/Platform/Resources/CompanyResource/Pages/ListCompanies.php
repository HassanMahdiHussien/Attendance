<?php

namespace App\Filament\Platform\Resources\CompanyResource\Pages;

use App\Filament\Platform\Resources\CompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
