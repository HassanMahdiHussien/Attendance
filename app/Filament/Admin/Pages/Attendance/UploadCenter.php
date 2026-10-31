<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Pages\Leave\LeaveBalanceBatches;
use App\Filament\Admin\Pages\Leave\LeaveBatches;
use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees;
use App\Models\Branch;
use App\Models\Company;
use App\Models\EmployeeImportRun;
use App\Services\Attendance\TransactionFile;
use App\Services\Attendance\TransactionImport;
use App\Services\Attendance\UploadDetector;
use App\Services\Branches\BranchNames;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeImport;
use App\Services\Employees\ImportPreview;
use App\Services\Leave\LeaveBalanceFile;
use App\Services\Leave\LeaveBalanceImport;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\LeaveImport;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

class UploadCenter extends AttendancePage
{
    use WithFileUploads;

    protected static ?string $slug = 'upload';

    protected string $view = 'filament.admin.pages.attendance.upload-center';

    public array $files = [];

    public array $incomingFiles = [];

    public array $rows = [];

    public array $branchChoices = [];

    public array $jurisdictionChoices = [];

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && ((parent::canAccess()) || ImportEmployees::canAccess());
    }

    public static function getNavigationLabel(): string
    {
        return __('messages.upload_center.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function updatedIncomingFiles(): void
    {
        $this->company();
        $this->validate(['incomingFiles' => 'array', 'incomingFiles.*' => 'file|max:102400']);
        foreach ($this->incomingFiles as $file) {
            $index = $this->files === [] ? 0 : max(array_keys($this->files)) + 1;
            $this->files[$index] = $file;
            $allowed = $this->allowedKinds();
            $detected = app(UploadDetector::class)->detect($this->company(), $file, $allowed);
            $kind = $detected['kind'];
            if ($kind !== 'unknown') {
                $this->authorizeKind($kind);
                $limit = $kind === 'employees' ? 10240 : ($kind === 'attendance' ? 102400 : 51200);
                $extensions = $kind === 'employees' ? 'csv,txt,xlsx' : 'csv,xlsx';
                $this->validate(["files.$index" => "file|extensions:$extensions|max:$limit"]);
            }
            $this->rows[$index] = [
                'kind' => $kind, 'name' => $file->getClientOriginalName(),
                'message' => $detected['message'] ?? null,
                'headers' => $detected['headers'], 'subheaders' => $detected['subheaders'] ?? [],
                'mapping' => $detected['mapping'] ?? [], 'settings' => $detected['settings'],
                'distinct_codes' => $detected['distinct_codes'] ?? null,
                'needs_mapping' => $detected['needs_mapping'] ?? false,
                'editing' => false, 'confirmed' => false, 'batch_id' => null, 'run_id' => null,
                'after_employee_preview' => false,
                'error' => null, 'preview_key' => bin2hex(random_bytes(16)),
            ];
            $this->savePreview($index, $detected['preview']);
        }
        $this->incomingFiles = [];
    }

    public function remove(int $index): void
    {
        $this->company();
        if (($this->rows[$index]['kind'] ?? null) === 'employees' && ($this->rows[$index]['run_id'] ?? null) !== null) {
            $run = EmployeeImportRun::query()->where('company_id', $this->company()->id)->find($this->rows[$index]['run_id']);
            if ($run !== null && in_array($run->status, ['queued', 'processing'], true)) {
                return;
            }
        }
        if (isset($this->rows[$index])) {
            Cache::forget($this->cacheKey($index));
        }
        unset($this->rows[$index], $this->files[$index]);
    }

    public function change(int $index): void
    {
        abort_if($this->authorizeRow($index)['kind'] === 'unknown', 404);
        $this->rows[$index]['editing'] = ! $this->rows[$index]['editing'];
    }

    public function refreshRow(int $index): void
    {
        $row = $this->authorizeRow($index);
        $kind = $row['kind'];
        abort_if($kind === 'unknown' || $row['batch_id'] !== null, 404);
        $file = $this->files[$index];
        $company = $this->company();
        $mapping = $row['mapping'];
        $settings = $row['settings'];
        $extensions = $kind === 'employees' ? 'csv,txt,xlsx' : 'csv,xlsx';
        $this->validate(["files.$index" => 'required|file|extensions:'.$extensions.'|max:'.($kind === 'employees' ? 10240 : ($kind === 'attendance' ? 102400 : 51200))]);
        // A file whose columns were just matched has no suggested coverage or scope yet.
        if ($row['needs_mapping'] && $kind === 'attendance') {
            $settings = app(UploadDetector::class)->attendanceSettings($company, $file,
                app(TransactionFile::class)->validateMapping($mapping, $row['headers']));
            $this->rows[$index]['settings'] = $settings;
        } elseif ($row['needs_mapping'] && $kind === 'leave') {
            $settings = app(UploadDetector::class)->leaveSettings($company, $file,
                app(LeaveFile::class)->validateMapping($mapping, $row['headers']), $settings);
            $this->rows[$index]['settings'] = $settings;
        }
        $preview = match ($kind) {
            'attendance' => app(TransactionImport::class)->preview($company, $file,
                app(TransactionFile::class)->validateMapping($mapping, $row['headers']), $settings),
            'leave' => app(LeaveImport::class)->preview($company, $file,
                app(LeaveFile::class)->validateMapping($mapping, $row['headers']), $settings),
            'leave_balance' => app(LeaveBalanceImport::class)->preview($company, $file,
                app(LeaveBalanceFile::class)->validateMapping($mapping, $row['headers']),
                $settings['date'] ?? '', (int) ($settings['year'] ?? 0)),
            'employees' => app(EmployeeImport::class)->previewMapped($company, $file,
                $settings['mode'], $settings['date'], app(ColumnMapping::class)->validate($mapping, $row['headers'], $settings['mode']))->toArray(),
        };
        $this->savePreview($index, $preview);
        if ($kind === 'attendance' && $row['distinct_codes'] === null && ($preview['counts']['unknown_ids'] ?? 0) > 0) {
            $this->rows[$index]['distinct_codes'] = $this->countDistinctCodes($index);
        }
        $this->rows[$index]['needs_mapping'] = false;
        $this->rows[$index]['editing'] = false;
        $this->rows[$index]['confirmed'] = false;
        $this->rows[$index]['error'] = null;
    }

    public function preview(int $index): ?array
    {
        $this->authorizeRow($index);
        $cached = Cache::get($this->cacheKey($index));

        return is_array($cached) ? $cached : null;
    }

    public function needsDecision(int $index): ?string
    {
        $row = $this->authorizeRow($index);
        $preview = $this->preview($index);
        if ($row['kind'] === 'unknown') {
            return __('messages.upload_center.unknown');
        }
        if ($row['needs_mapping']) {
            return __('messages.upload_center.mapping_needed');
        }
        if ($row['editing']) {
            return __('messages.upload_center.preview_needed');
        }
        if ($row['kind'] === 'leave_balance' && (($row['settings']['date'] ?? null) === null || ($row['settings']['year'] ?? null) === null)) {
            return __('messages.upload_center.date_needed');
        }
        if ($preview === null) {
            return __('messages.upload_center.preview_needed');
        }
        if (($preview['already_imported'] ?? $preview['alreadyImported'] ?? null) !== null || empty($preview['token'])) {
            return __('messages.upload_center.already_imported');
        }
        if ($row['kind'] === 'employees') {
            $employees = ImportPreview::fromArray($preview);
            if ($employees->errors() > 0) {
                return __('messages.upload_center.errors_needed');
            }
            if ($employees->held() > 0) {
                return __('messages.upload_center.branches_needed');
            }
        }
        if ($row['kind'] === 'attendance') {
            if (($row['settings']['branch_ids'] ?? []) === []) {
                return __('messages.upload_center.coverage_needed');
            }
            $unknown = $preview['counts']['unknown_ids'] ?? 0;
            $known = max(0, ($row['distinct_codes'] ?? 0) - $unknown);
            if ($unknown > $known && ! $row['confirmed']) {
                return __('messages.upload_center.confirm_file');
            }
        }

        return null;
    }

    public function requiresConfirmation(int $index): bool
    {
        $row = $this->authorizeRow($index);
        $preview = $this->preview($index);

        return $row['kind'] === 'attendance' && $preview !== null
            && ($preview['counts']['unknown_ids'] ?? 0) > max(0, ($row['distinct_codes'] ?? 0) - ($preview['counts']['unknown_ids'] ?? 0));
    }

    public function summary(int $index): string
    {
        $row = $this->authorizeRow($index);
        $preview = $this->preview($index);
        if ($preview === null) {
            if ($row['kind'] === 'unknown' && $row['message'] !== null) {
                return $row['message'];
            }

            return $this->needsDecision($index) ?? '';
        }
        $counts = $preview['counts'] ?? [];

        return match ($row['kind']) {
            'attendance' => __('messages.upload_center.attendance_summary', [
                'from' => $this->ltr(substr($row['settings']['from'] ?? '', 0, 10)), 'to' => $this->ltr(substr($row['settings']['to'] ?? '', 0, 10)),
                'branches' => implode(', ', Branch::query()->where('company_id', $this->company()->id)->whereIn('id', $row['settings']['branch_ids'] ?? [])->pluck('name')->all()),
                'rows' => $counts['rows'] ?? 0, 'unknown' => $counts['unknown_ids'] ?? 0,
                'devices' => $counts['new_devices'] ?? 0, 'errors' => $counts['errors'] ?? 0,
            ]),
            'leave' => __('messages.upload_center.leave_summary', [
                'from' => $this->ltr($row['settings']['period_from'] ?? ''), 'to' => $this->ltr($row['settings']['period_to'] ?? ''),
                'new' => $counts['new'] ?? 0, 'changed' => $counts['changed'] ?? 0,
                'unknown' => $counts['unknown_employees'] ?? 0, 'errors' => $counts['errors'] ?? 0,
            ]),
            'leave_balance' => __('messages.upload_center.balance_summary', [
                'date' => $this->ltr($row['settings']['date'] ?? ''), 'employees' => $counts['matched'] ?? 0,
                'unknown' => $counts['unknown'] ?? 0, 'errors' => $counts['invalid'] ?? 0,
            ]),
            'employees' => __('messages.upload_center.employees_summary', [
                'new' => ImportPreview::fromArray($preview)->added(), 'changed' => ImportPreview::fromArray($preview)->changed(),
                'held' => ImportPreview::fromArray($preview)->held(), 'errors' => ImportPreview::fromArray($preview)->errors(),
            ]),
        };
    }

    public function import(): void
    {
        $this->company();
        $this->pollImport();
        $earlierImported = collect($this->rows)->contains(fn (array $row): bool => $row['kind'] === 'employees' && $row['batch_id'] !== null);
        if ($this->hasBlockingEmployeeRun()) {
            return;
        }
        foreach (['employees', 'leave_balance', 'leave', 'attendance'] as $kind) {
            foreach (array_keys($this->rows) as $index) {
                if (($this->rows[$index]['kind'] ?? null) !== $kind || $this->rows[$index]['batch_id'] !== null) {
                    continue;
                }
                try {
                    $this->authorizeRow($index);
                    if ($earlierImported && ! ($this->rows[$index]['after_employee_preview'] ?? false)
                        && $this->preview($index) !== null && ! $this->rows[$index]['needs_mapping'] && ! $this->rows[$index]['editing']) {
                        $confirmed = $this->rows[$index]['confirmed'];
                        $this->refreshRow($index);
                        $this->rows[$index]['confirmed'] = $confirmed;
                        $this->rows[$index]['after_employee_preview'] = true;
                    }
                    if ($this->needsDecision($index) !== null) {
                        continue;
                    }
                    $row = $this->rows[$index];
                    $preview = $this->preview($index);
                    $file = $this->files[$index];
                    $company = $this->company();
                    $batch = match ($kind) {
                        'employees' => app(EmployeeImport::class)->queueMapped($company, $file, auth()->user(), $row['settings']['mode'], $row['settings']['date'], $row['mapping'], $preview['token']),
                        'leave_balance' => app(LeaveBalanceImport::class)->commit($company, $file, auth()->user(), $row['mapping'], $row['settings']['date'], (int) $row['settings']['year'], $preview['token']),
                        'leave' => app(LeaveImport::class)->commit($company, $file, auth()->user(), $row['mapping'], $row['settings'], $preview['token']),
                        'attendance' => app(TransactionImport::class)->commit($company, $file, auth()->user(), $row['mapping'], $row['settings'], $preview['token']),
                    };
                    if ($kind === 'employees') {
                        $this->rows[$index]['run_id'] = $batch->id;
                        Cache::forget($this->cacheKey($index));

                        return;
                    }
                    $this->rows[$index]['batch_id'] = $batch->id;
                    $earlierImported = true;
                    Cache::forget($this->cacheKey($index));
                } catch (ValidationException $exception) {
                    $this->rows[$index]['error'] = __('messages.upload_center.failed').' '.collect($exception->errors())->flatten()->first();

                    return;
                } catch (\Throwable $exception) {
                    report($exception);
                    $this->rows[$index]['error'] = __('messages.upload_center.failed');

                    return;
                }
            }
        }
    }

    public function pollImport(): void
    {
        $company = $this->company();
        $completedNow = false;
        foreach ($this->rows as $index => $row) {
            if ($row['kind'] !== 'employees' || ($row['run_id'] ?? null) === null) {
                continue;
            }
            $run = EmployeeImportRun::query()->where('company_id', $company->id)->find($row['run_id']);
            if ($run === null) {
                $this->rows[$index]['error'] = __('messages.employee_import_ui.import_failed');
            } elseif ($run->status === 'completed') {
                $completedNow = $completedNow || $row['batch_id'] === null;
                $this->rows[$index]['batch_id'] = $run->employee_import_batch_id;
                $this->rows[$index]['error'] = null;
            } elseif ($run->status === 'failed') {
                $this->rows[$index]['error'] = __('messages.employee_import_ui.import_failed');
            }
        }
        if ($completedNow) {
            foreach ($this->rows as $index => $row) {
                if (in_array($row['kind'], ['employees', 'unknown'], true) || $row['batch_id'] !== null || $row['needs_mapping'] || $row['editing']) {
                    continue;
                }
                try {
                    $confirmed = $row['confirmed'];
                    $this->refreshRow($index);
                    $this->rows[$index]['confirmed'] = $confirmed;
                    $this->rows[$index]['after_employee_preview'] = true;
                } catch (ValidationException) {
                    $this->rows[$index]['error'] = __('messages.upload_center.preview_needed');
                }
            }
        }
    }

    public function hasPendingEmployeeRun(): bool
    {
        $this->company();

        return collect($this->rows)->contains(function (array $row): bool {
            if ($row['kind'] !== 'employees' || ($row['run_id'] ?? null) === null) {
                return false;
            }
            $run = EmployeeImportRun::query()->where('company_id', $this->company()->id)->find($row['run_id']);

            return $run !== null && in_array($run->status, ['queued', 'processing'], true);
        });
    }

    private function hasBlockingEmployeeRun(): bool
    {
        return collect($this->rows)->contains(function (array $row): bool {
            if ($row['kind'] !== 'employees' || ($row['run_id'] ?? null) === null || $row['batch_id'] !== null) {
                return false;
            }
            $run = EmployeeImportRun::query()->where('company_id', $this->company()->id)->find($row['run_id']);

            return $run === null || in_array($run->status, ['queued', 'processing', 'failed'], true);
        });
    }

    // Keeps ISO dates readable inside Arabic sentences (left-to-right isolate).
    private function ltr(string $value): string
    {
        return $value === '' ? '' : "\u{2066}{$value}\u{2069}";
    }

    public function fieldLabel(string $kind, string $field): string
    {
        $group = match ($kind) {
            'attendance' => 'messages.attendance_ui.fields.', 'leave' => 'messages.leave_ui.fields.',
            'leave_balance' => 'messages.leave_balance_ui.fields.', default => 'messages.employee_import_ui.fields.',
        };
        $key = $group.$field;

        return __($key);
    }

    public function countLabel(string $kind, string $count): string
    {
        $group = match ($kind) {
            'attendance' => 'messages.attendance_ui.count_labels.', 'leave' => 'messages.leave_ui.counts.',
            'leave_balance' => 'messages.leave_balance_ui.counts.', default => 'messages.upload_center.counts.',
        };
        $key = $group.$count;

        return __($key);
    }

    public function batchUrl(int $index): string
    {
        $row = $this->authorizeRow($index);

        return match ($row['kind']) {
            'attendance' => AttendanceBatches::getUrl(panel: 'admin', tenant: $this->company()),
            'leave' => LeaveBatches::getUrl(panel: 'admin', tenant: $this->company()),
            'leave_balance' => LeaveBalanceBatches::getUrl(panel: 'admin', tenant: $this->company()),
            'employees' => ImportEmployees::getUrl(panel: 'admin', tenant: $this->company()),
        };
    }

    public function linkBranch(int $index, int $unmatched): void
    {
        $row = $this->authorizeRow($index);
        abort_unless($row['kind'] === 'employees', 403);
        $preview = $this->preview($index);
        abort_unless($preview !== null, 404);
        $item = ImportPreview::fromArray($preview)->unmatchedBranches()[$unmatched] ?? null;
        abort_unless($item !== null, 404);
        $this->validate(["branchChoices.$index.$unmatched" => 'required|integer']);
        app(BranchNames::class)->link($this->company(), $item['name'], (int) $this->branchChoices[$index][$unmatched]);
        $this->refreshRow($index);
    }

    public function createBranch(int $index, int $unmatched): void
    {
        $row = $this->authorizeRow($index);
        abort_unless($row['kind'] === 'employees', 403);
        Gate::authorize('create', Branch::class);
        $preview = $this->preview($index);
        abort_unless($preview !== null, 404);
        $item = ImportPreview::fromArray($preview)->unmatchedBranches()[$unmatched] ?? null;
        abort_unless($item !== null, 404);
        $this->validate(["jurisdictionChoices.$index.$unmatched" => 'required|in:federal_iraq,kurdistan_region']);
        app(BranchNames::class)->create($this->company(), $item['name'], $this->jurisdictionChoices[$index][$unmatched]);
        $this->refreshRow($index);
    }

    private function savePreview(int $index, ?array $preview): void
    {
        Cache::forget($this->cacheKey($index));
        if ($preview !== null) {
            Cache::put($this->cacheKey($index), $preview, now()->addMinutes(30));
        }
    }

    private function cacheKey(int $index): string
    {
        return 'upload-center:'.$this->company()->id.':'.auth()->id().':'.($this->rows[$index]['preview_key'] ?? 'none');
    }

    private function allowedKinds(): array
    {
        return array_values(array_filter(['attendance', 'leave', 'leave_balance', 'employees'],
            fn (string $kind): bool => $kind === 'employees' ? ImportEmployees::canAccess() : parent::canAccess()));
    }

    private function authorizeKind(string $kind): void
    {
        abort_unless(in_array($kind, $this->allowedKinds(), true), 403);
    }

    private function authorizeRow(int $index): array
    {
        $this->company();
        abort_unless(isset($this->rows[$index], $this->files[$index]), 404);
        if ($this->rows[$index]['kind'] !== 'unknown') {
            $this->authorizeKind($this->rows[$index]['kind']);
        }

        return $this->rows[$index];
    }

    private function countDistinctCodes(int $index): int
    {
        $row = $this->rows[$index];
        $codes = [];
        foreach (app(TransactionFile::class)->rows($this->files[$index], $row['mapping']) as $line) {
            if ($line['errors'] === [] && $line['values']['person_code'] !== '') {
                $codes[$line['values']['person_code']] = true;
            }
        }

        return count($codes);
    }
}
