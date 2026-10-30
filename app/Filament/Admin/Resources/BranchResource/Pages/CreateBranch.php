<?php

namespace App\Filament\Admin\Resources\BranchResource\Pages;

use App\Filament\Admin\Resources\BranchResource;
use App\Models\Branch;
use App\Services\Branches\BranchNames;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    private array $alternativeNames = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->alternativeNames = $data['alternative_names'] ?? [];
        unset($data['alternative_names']);
        $branch = new Branch($data + ['company_id' => Filament::getTenant()->id]);
        app(BranchNames::class)->validateNames($branch, $this->alternativeNames);

        return $data;
    }

    protected function afterCreate(): void
    {
        app(BranchNames::class)->save($this->record, $this->alternativeNames);
    }
}
