<?php

namespace App\Filament\Admin\Resources\CommitteeMandateResource\Pages;

use App\Filament\Admin\Resources\CommitteeMandateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMandates extends ListRecords
{
    protected static string $resource = CommitteeMandateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
