<?php

namespace App\Filament\Platform\Resources\LawResource\Pages;

use App\Filament\Platform\Resources\LawResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLaws extends ListRecords
{
    protected static string $resource = LawResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
