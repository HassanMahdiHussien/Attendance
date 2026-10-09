<?php

namespace App\Filament\Admin\Resources\ViolationTypeResource\Pages;

use App\Filament\Admin\Resources\ViolationTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListViolationTypes extends ListRecords
{
    protected static string $resource = ViolationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
