<?php

namespace App\Filament\Admin\Resources\BranchResource\Pages;

use App\Filament\Admin\Resources\BranchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBranchs extends ListRecords
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
