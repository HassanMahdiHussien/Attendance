<?php

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Resources\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importEmployees')->label('استيراد من CSV')
                ->visible(fn (): bool => ImportEmployees::canAccess())
                ->url(fn (): string => EmployeeResource::getUrl('import')),
            CreateAction::make(),
        ];
    }
}
