<?php

namespace App\Filament\Admin\Resources\BranchResource\Pages;

use App\Filament\Admin\Resources\BranchResource;
use App\Services\Branches\BranchNames;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    private array $alternativeNames = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['alternative_names'] = $this->record->aliases()->pluck('name')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->alternativeNames = $data['alternative_names'] ?? [];
        unset($data['alternative_names']);
        $this->record->name = $data['name'];
        app(BranchNames::class)->validateNames($this->record, $this->alternativeNames);

        return $data;
    }

    protected function afterSave(): void
    {
        app(BranchNames::class)->save($this->record, $this->alternativeNames);
    }
}
