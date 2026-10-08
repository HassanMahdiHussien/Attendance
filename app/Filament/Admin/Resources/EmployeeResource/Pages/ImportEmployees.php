<?php

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Resources\EmployeeResource;
use App\Models\Company;
use App\Services\Employees\EmployeeImport;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class ImportEmployees extends Page
{
    use WithFileUploads;

    protected static string $resource = EmployeeResource::class;

    protected static ?string $title = 'استيراد الموظفين من CSV';

    protected string $view = 'filament.admin.resources.employee-resource.pages.import-employees';

    public $sourceFile;

    #[Locked]
    public ?array $previewRows = null;

    public array $notices = [];

    #[Locked]
    public ?string $previewHash = null;

    public ?array $summary = null;

    public static function canAccess(array $parameters = []): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company && Gate::allows('importEmployees', $tenant);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function previewImport(EmployeeImport $import): void
    {
        $company = $this->company();
        Gate::authorize('importEmployees', $company);
        $this->validate(['sourceFile' => 'required|file|max:2048']);
        $preview = $import->preview($company, $this->sourceFile);
        $this->previewRows = $preview->rows;
        $this->notices = $preview->notices;
        $this->previewHash = $preview->hash;
        $this->summary = null;
    }

    public function confirmImport(EmployeeImport $import): void
    {
        $company = $this->company();
        Gate::authorize('importEmployees', $company);
        abort_unless($this->previewRows !== null && $this->previewHash !== null, 403);
        $this->validate(['sourceFile' => 'required|file|max:2048']);
        if (count(array_filter($this->previewRows, fn (array $row): bool => $row['status'] === 'error')) > 0) {
            return;
        }
        $result = $import->commit($company, $this->sourceFile, auth()->user(), $this->previewHash);
        $this->previewRows = $result['preview']->rows;
        $this->notices = $result['preview']->notices;
        $this->previewHash = $result['preview']->hash;
        if (! $result['committed']) {
            Notification::make()->title('تغيّرت البيانات. راجع المعاينة من جديد.')->danger()->send();

            return;
        }
        $this->summary = ['added' => $result['added'], 'updated' => $result['updated']];
        $this->sourceFile = null;
        $this->previewRows = null;
        $this->previewHash = null;
        Notification::make()->title('اكتمل استيراد الموظفين')->success()->send();
    }

    private function company(): Company
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Company, 403);

        return $tenant;
    }
}
