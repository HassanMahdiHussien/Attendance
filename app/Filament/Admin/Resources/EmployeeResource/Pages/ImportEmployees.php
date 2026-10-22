<?php

namespace App\Filament\Admin\Resources\EmployeeResource\Pages;

use App\Filament\Admin\Resources\EmployeeResource;
use App\Models\Company;
use App\Models\EmployeeImportBatch;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeImport;
use App\Services\Employees\ImportPreview;
use App\Services\Employees\SpreadsheetReader;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class ImportEmployees extends Page
{
    use WithFileUploads;

    protected static string $resource = EmployeeResource::class;

    public function getTitle(): string
    {
        return __('messages.employee_import_ui.title');
    }

    protected string $view = 'filament.admin.resources.employee-resource.pages.import-employees';

    public $sourceFile;

    #[Locked]
    public ?array $previewRows = null;

    public array $notices = [];

    #[Locked]
    public ?string $previewHash = null;

    public ?array $summary = null;

    public string $mode = 'full';

    public string $effectiveDate = '';

    public string $step = 'upload';

    public array $headers = [];

    public array $mapping = [];

    public array $samples = [];

    #[Locked]
    public ?string $mappedPreviewKey = null;

    public static function canAccess(array $parameters = []): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company && Gate::allows('importEmployees', $tenant);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->effectiveDate = today()->toDateString();
    }

    public function prepareMapping(EmployeeImport $import, ColumnMapping $columns): void
    {
        $company = $this->company();
        Gate::authorize('importEmployees', $company);
        $this->validate(['sourceFile' => 'required|file|max:10240', 'mode' => 'required|in:full,partial', 'effectiveDate' => 'required|date']);
        $sheet = $import->inspect($company, $this->sourceFile);
        $this->headers = $sheet['headers'];
        $this->mapping = $columns->saved($company, $this->headers);
        $this->refreshSamples(app(SpreadsheetReader::class));
        $this->mappedPreviewKey = null;
        $this->summary = null;
        $this->step = 'mapping';
    }

    public function updatedMapping(): void
    {
        Gate::authorize('importEmployees', $this->company());
        $this->refreshSamples(app(SpreadsheetReader::class));
        $this->mappedPreviewKey = null;
        $this->step = 'mapping';
    }

    public function previewMappedImport(EmployeeImport $import, ColumnMapping $columns): void
    {
        $company = $this->company();
        Gate::authorize('importEmployees', $company);
        $this->validate(['sourceFile' => 'required|file|max:10240', 'mode' => 'required|in:full,partial', 'effectiveDate' => 'required|date']);
        $sheet = $import->inspect($company, $this->sourceFile);
        if ($sheet['headers'] !== $this->headers) {
            $this->addError('sourceFile', __('messages.employee_import.file_changed'));

            return;
        }
        $mapping = $columns->validate($this->mapping, $this->headers, $this->mode);
        $preview = $import->previewMapped($company, $this->sourceFile, $this->mode, $this->effectiveDate, $mapping);
        $this->mappedPreviewKey = bin2hex(random_bytes(16));
        Cache::put($this->cacheKey(), $preview->toArray(), now()->addMinutes(30));
        $this->step = 'preview';
    }

    public function confirmMappedImport(EmployeeImport $import): void
    {
        $company = $this->company();
        Gate::authorize('importEmployees', $company);
        $preview = $this->mappedPreview();
        abort_unless($preview instanceof ImportPreview && $preview->token !== null, 403);
        $this->validate(['sourceFile' => 'required|file|max:10240']);
        $result = $import->commitMapped($company, $this->sourceFile, auth()->user(), $this->mode, $this->effectiveDate, $this->mapping, $preview->token);
        if (! $result['committed']) {
            Cache::put($this->cacheKey(), $result['preview']->toArray(), now()->addMinutes(30));
            Notification::make()->title(__('messages.employee_import_ui.review_again'))->danger()->send();

            return;
        }
        Cache::forget($this->cacheKey());
        $this->mappedPreviewKey = null;
        $this->sourceFile = null;
        $this->step = 'upload';
        $this->summary = ['counts' => $result['counts'], 'batch_id' => $result['batch']->id];
        Notification::make()->title(__('Employee import completed'))->success()->send();
    }

    public function mappedPreview(): ?ImportPreview
    {
        // The cache stores arrays: the database store refuses to unserialize application classes (config cache.serializable_classes).
        $cached = $this->mappedPreviewKey === null ? null : Cache::get($this->cacheKey());

        return is_array($cached) ? ImportPreview::fromArray($cached) : null;
    }

    public function recentBatches(): Collection
    {
        $company = $this->company();
        Gate::authorize('importEmployees', $company);

        return EmployeeImportBatch::query()->where('company_id', $company->id)->latest('id')->limit(10)->get();
    }

    private function cacheKey(): string
    {
        return 'employee-import-preview:'.$this->company()->id.':'.auth()->id().':'.$this->mappedPreviewKey;
    }

    private function refreshSamples(SpreadsheetReader $reader): void
    {
        $sheet = $reader->read($this->sourceFile);
        $this->samples = [];
        foreach ($this->mapping as $index => $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }
            foreach ($sheet['rows'] as $row) {
                try {
                    $sample = $reader->value($row['cells'][$index] ?? null, $field);
                } catch (ValidationException) {
                    $sample = '';
                }
                if ($sample !== '') {
                    $this->samples[$index] = $sample;
                    break;
                }
            }
        }
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
            Notification::make()->title(__('The data changed. Review the preview again.'))->danger()->send();

            return;
        }
        $this->summary = ['added' => $result['added'], 'updated' => $result['updated']];
        $this->sourceFile = null;
        $this->previewRows = null;
        $this->previewHash = null;
        Notification::make()->title(__('Employee import completed'))->success()->send();
    }

    private function company(): Company
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Company, 403);

        return $tenant;
    }
}
