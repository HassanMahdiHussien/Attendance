<?php

namespace App\Livewire\Attendance;

use App\Filament\Admin\Pages\Attendance\HolidayReview;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidaySource;
use App\Models\Attendance\HolidayVersion;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\AnnouncementPeople;
use App\Services\Attendance\AnnouncementPreview;
use App\Services\Attendance\AnnouncementProgress;
use App\Services\Attendance\Holidays;
use App\Services\Attendance\HolidaySources;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class AnnouncementReview extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $sourceId;

    public ?string $kind = null;

    public string $name = '';

    public string $from = '';

    public string $to = '';

    public array $extraRanges = [];

    public bool $proposalLoaded = false;

    public string $departmentSearch = '';

    public string $who = '';

    public array $departments = [];

    public array $branchIds = [];

    public array $legalEntities = [];

    public array $workPatterns = [];

    public array $employeeIds = [];

    public array $unknownCodes = [];

    public bool $everyoneConfirmed = false;

    public string $note = '';

    public $peopleFile;

    public function mount(int $sourceId): void
    {
        $this->sourceId = $sourceId;
        $source = $this->source();
        $this->name = pathinfo($source->file_name, PATHINFO_FILENAME);
        $this->refreshProposal();
    }

    public function refreshProposal(): void
    {
        if ($this->proposalLoaded) {
            return;
        }

        $source = $this->source();
        if ($source->ai_status !== 'done' || ! is_array($source->proposal)) {
            return;
        }

        $proposal = $source->proposal;
        $this->kind ??= $proposal['kind'] ?? null;
        if ($this->name === pathinfo($source->file_name, PATHINFO_FILENAME)) {
            $this->name = $proposal['name_en'] ?? $this->name;
        }
        $this->from = $this->from ?: ($proposal['ranges'][0]['from'] ?? '');
        $this->to = $this->to ?: ($proposal['ranges'][0]['to'] ?? '');
        $this->extraRanges = $this->extraRanges ?: array_slice($proposal['ranges'] ?? [], 1);
        $this->departments = $this->departments ?: ($proposal['departments'] ?? []);
        $this->branchIds = $this->branchIds ?: Branch::query()->where('company_id', $source->company_id)
            ->whereIn('name', $proposal['branch_names'] ?? [])->pluck('id')->all();
        $this->legalEntities = $this->legalEntities ?: ($proposal['legal_entities'] ?? []);
        $this->workPatterns = $this->workPatterns ?: ($proposal['work_patterns'] ?? []);
        foreach ($source->parts ?? [] as $part) {
            if (($part['kind'] ?? null) === 'employee_list') {
                $this->addCodes($part['codes'] ?? []);
            }
        }
        if ($this->who === '' && $this->peopleChosen()) {
            $this->who = 'selected';
        }
        $this->proposalLoaded = true;
    }

    private function company(): Company
    {
        $company = Filament::getTenant();
        abort_unless($company instanceof Company && auth()->user()?->is_active
            && auth()->user()->canAccessTenant($company), 403);
        Gate::authorize('update', $company);

        return $company;
    }

    public function source(): HolidaySource
    {
        return HolidaySource::query()->where('company_id', $this->company()->id)->findOrFail($this->sourceId);
    }

    public function holiday(): ?HolidayVersion
    {
        return Holiday::query()->with('currentVersion')->where('company_id', $this->company()->id)
            ->whereIn('status', ['draft', 'approved'])
            ->whereHas('currentVersion', fn ($query) => $query->where('source_id', $this->sourceId))
            ->latest('id')->first()?->currentVersion;
    }

    public function addRange(): void
    {
        $this->extraRanges[] = ['from' => '', 'to' => ''];
    }

    public function removeRange(int $index): void
    {
        unset($this->extraRanges[$index]);

        $this->extraRanges = array_values($this->extraRanges);
    }

    public function addDepartment(string $department): void
    {
        if (! in_array($department, $this->departments, true)) {
            $this->departments[] = $department;
        }

        $this->who = 'selected';
    }

    public function removeDepartment(string $department): void
    {
        $this->departments = array_values(array_diff($this->departments, [$department]));
    }

    public function departmentOptions(): array
    {
        return Employee::query()->where('company_id', $this->company()->id)
            ->whereNotNull('department')->distinct()->orderBy('department')->pluck('department')
            ->filter(fn (string $value): bool => $this->departmentSearch === ''
                || mb_stripos($value, $this->departmentSearch) !== false)->take(50)->all();
    }

    public function updatedPeopleFile(): void
    {
        $this->validate(['peopleFile' => 'required|file|extensions:xlsx,csv|max:10240']);
        try {
            $this->addCodes(app(AnnouncementPeople::class)->read($this->company(), $this->peopleFile)['codes']);
        } catch (ValidationException $exception) {
            $this->addError('peopleFile', collect($exception->errors())->flatten()->first());

            return;
        }
        $this->peopleFile = null;
        $this->who = 'selected';
    }

    private function addCodes(array $codes): void
    {
        $found = Employee::query()->where('company_id', $this->company()->id)
            ->whereIn('employee_code', $codes)->pluck('id', 'employee_code');
        foreach ($codes as $code) {
            if ($found->has($code)) {
                $this->employeeIds[] = (int) $found[$code];
            } else {
                $this->unknownCodes[] = $code;
            }
        }
        $this->employeeIds = array_values(array_unique($this->employeeIds));
        $this->unknownCodes = array_values(array_unique($this->unknownCodes));
    }

    public function preview(): ?array
    {
        if ($this->from === '' || $this->who === '' || ($this->who === 'selected' && ! $this->hasSelection())) {
            return null;
        }

        return app(AnnouncementPreview::class)->forDate($this->company(), $this->from, [
            'everyone' => $this->who === 'everyone', 'departments' => $this->departments,
            'employee_ids' => $this->who === 'everyone' ? [] : $this->employeeIds,
            'branch_ids' => $this->who === 'everyone' ? [] : array_map('intval', $this->branchIds),
            'legal_entities' => $this->who === 'everyone' ? [] : $this->legalEntities,
            'work_patterns' => $this->who === 'everyone' ? [] : $this->workPatterns,
        ]);
    }

    // A field day needs named people: a branch alone would mark everyone in it as working away.
    public function peopleChosen(): bool
    {
        return $this->kind === 'field_work'
            ? $this->departments !== [] || $this->employeeIds !== []
            : $this->hasSelection();
    }

    public function hasSelection(): bool
    {
        return $this->departments !== [] || $this->employeeIds !== [] || $this->branchIds !== []
            || $this->legalEntities !== [] || $this->workPatterns !== [];
    }

    public function approve(Holidays $holidays): void
    {
        $source = $this->source();
        abort_unless($this->holiday() === null, 409);
        $this->validate([
            'kind' => 'required|in:holiday,field_work', 'name' => 'required|string|max:255',
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'who' => 'required|in:everyone,selected',
            'extraRanges' => 'array',
            'extraRanges.*.from' => 'required|date_format:Y-m-d',
            'extraRanges.*.to' => 'required|date_format:Y-m-d',
            'note' => 'nullable|string|max:500',
        ]);
        if ($this->who === 'selected') {
            abort_unless($this->peopleChosen(), 422);
        }
        if ($this->kind === 'field_work' && $this->who === 'everyone') {
            abort_unless($this->everyoneConfirmed, 422);
        }
        $holidays->approveFromSource($source, [
            'kind' => $this->kind, 'name_en' => $this->name, 'name_ar' => $source->proposal['name_ar'] ?? null,
            'ranges' => [['from' => $this->from, 'to' => $this->to], ...$this->extraRanges],
            'departments' => $this->who === 'everyone' ? [] : $this->departments,
            'employee_ids' => $this->who === 'everyone' ? [] : $this->employeeIds,
            'branch_ids' => $this->who === 'everyone' ? [] : $this->branchIds,
            'legal_entities' => $this->who === 'everyone' ? [] : $this->legalEntities,
            'work_patterns' => $this->who === 'everyone' ? [] : $this->workPatterns,
            'everyone' => $this->who === 'everyone',
            'everyone_confirmed' => $this->everyoneConfirmed,
        ], auth()->user(), $this->note);
    }

    public function close(HolidaySources $sources): void
    {
        $sources->close($this->source(), auth()->user());
    }

    public function reopen(HolidaySources $sources): void
    {
        $sources->reopen($this->source(), auth()->user());
    }

    public function retry(HolidaySources $sources): void
    {
        $sources->retry($this->source(), auth()->user());
    }

    public function detailsUrl(?int $holidayId = null): string
    {
        return HolidayReview::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(
            $holidayId === null ? ['source' => $this->sourceId] : ['holiday' => $holidayId]);
    }

    public function progress(): ?array
    {
        $holiday = $this->holiday()?->holiday;

        return $holiday ? app(AnnouncementProgress::class)->forHoliday($holiday) : null;
    }

    public function render(): View
    {
        return view('livewire.attendance.announcement-review');
    }
}
