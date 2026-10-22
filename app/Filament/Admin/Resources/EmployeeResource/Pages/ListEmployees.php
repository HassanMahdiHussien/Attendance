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
            Action::make('importEmployees')->label(__('messages.employee_import_ui.title'))
                ->visible(fn (): bool => ImportEmployees::canAccess())
                ->url(fn (): string => EmployeeResource::getUrl('import')),
            Action::make('exitReview')->label(__('Exit review'))
                ->visible(fn (): bool => ExitReviewEmployees::canAccess())
                ->url(fn (): string => EmployeeResource::getUrl('exit-review')),
            CreateAction::make(),
        ];
    }
}
