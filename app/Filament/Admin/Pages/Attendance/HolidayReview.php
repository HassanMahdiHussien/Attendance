<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Day;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidaySource;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Attendance\Holidays;
use Livewire\Attributes\Locked;

class HolidayReview extends AttendancePage
{
    protected static ?string $slug = 'attendance/holidays/review';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.attendance.holiday-review';

    #[Locked]
    public ?int $holidayId = null;

    #[Locked]
    public ?int $sourceId = null;

    public string $nameEn = '';

    public string $nameAr = '';

    public array $ranges = [['from' => '', 'to' => '']];

    public array $legalEntities = [];

    public array $branchIds = [];

    public array $workPatterns = [];

    public string $exceptionsNote = '';

    public string $reason = '';

    public int $partIndex = 0;

    public static function getNavigationLabel(): string
    {
        return __('messages.holiday_ui.review');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return $this->holiday()?->currentVersion?->name_en;
    }

    public function mount(): void
    {
        parent::mount();
        $holiday = request()->query('holiday');
        $source = request()->query('source');
        if ($holiday !== null) {
            abort_unless(ctype_digit((string) $holiday), 404);
            $this->holidayId = (int) $holiday;
            $version = $this->holiday()->currentVersion;
            $this->nameEn = $version->name_en;
            $this->nameAr = $version->name_ar ?? '';
            $this->ranges = $version->ranges ?: $this->ranges;
            $this->legalEntities = $version->legal_entities ?: [];
            $this->branchIds = $version->branch_ids ?: [];
            $this->workPatterns = $version->work_patterns ?: [];
            $this->exceptionsNote = $version->exceptions_note ?? '';
            $this->sourceId = $version->source_id;
        } elseif ($source !== null) {
            abort_unless(ctype_digit((string) $source), 404);
            $this->sourceId = (int) $source;
            $proposal = $this->source()?->proposal;
            if (is_array($proposal)) {
                $this->nameEn = $proposal['name_en'];
                $this->nameAr = $proposal['name_ar'] ?? '';
                $this->ranges = $proposal['ranges'] ?: $this->ranges;
                $this->legalEntities = $proposal['legal_entities'];
                $this->branchIds = Branch::query()->where('company_id', $this->company()->id)
                    ->whereIn('name', $proposal['branch_names'])->pluck('id')->all();
                $this->workPatterns = $proposal['work_patterns'];
                $this->exceptionsNote = implode("\n", [...$proposal['exception_quotes'], ...$proposal['notes']]);
            }
        }
    }

    public function holiday(): ?Holiday
    {
        return $this->holidayId === null ? null : Holiday::query()->with(['currentVersion', 'versions.decider'])
            ->where('company_id', $this->company()->id)->findOrFail($this->holidayId);
    }

    public function source(): ?HolidaySource
    {
        return $this->sourceId === null ? null : HolidaySource::query()
            ->where('company_id', $this->company()->id)->findOrFail($this->sourceId);
    }

    public function parts(): array
    {
        return $this->source()?->parts ?? [];
    }

    public function part(): ?array
    {
        return $this->parts()[$this->partIndex] ?? null;
    }

    public function selectPart(int $index): void
    {
        $this->company();
        abort_unless(isset($this->parts()[$index]), 404);
        $this->partIndex = $index;
    }

    public function nextPart(): void
    {
        $this->selectPart(min(count($this->parts()) - 1, $this->partIndex + 1));
    }

    public function previousPart(): void
    {
        $this->selectPart(max(0, $this->partIndex - 1));
    }

    public function addRange(): void
    {
        $this->company();
        $this->ranges[] = ['from' => '', 'to' => ''];
    }

    public function removeRange(int $index): void
    {
        $this->company();
        unset($this->ranges[$index]);
        $this->ranges = array_values($this->ranges);
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function entities(): array
    {
        return Employee::query()->where('company_id', $this->company()->id)->whereNotNull('legal_entity')
            ->where('legal_entity', '!=', '')->distinct()->orderBy('legal_entity')->pluck('legal_entity')->all();
    }

    public function save(Holidays $holidays): void
    {
        $this->validateForm(false);
        $holiday = $this->holiday();
        if ($holiday === null) {
            $created = $holidays->create($this->company(), $this->values(), auth()->user());
            $this->redirect(static::getUrl(panel: 'admin', tenant: $this->company())
                .'?'.http_build_query(['holiday' => $created->id]));

            return;
        }
        abort_unless($holiday->status === 'approved', 409);
        $this->requireReason();
        $holidays->edit($holiday, $this->values(), auth()->user(), $this->reason);
        $this->reason = '';
    }

    public function approve(Holidays $holidays): void
    {
        $holiday = $this->holiday();
        abort_unless($holiday?->status === 'draft', 409);
        $this->validateForm(true);
        $this->requireReason();
        $holidays->approve($holiday, $this->values(), auth()->user(), $this->reason);
        $this->reason = '';
    }

    public function reject(Holidays $holidays): void
    {
        $holiday = $this->holiday();
        abort_unless($holiday?->status === 'draft', 409);
        $this->requireReason();
        $holidays->reject($holiday, auth()->user(), $this->reason);
        $this->reason = '';
    }

    public function withdraw(Holidays $holidays): void
    {
        $holiday = $this->holiday();
        abort_unless($holiday?->status === 'approved', 409);
        $this->requireReason();
        $holidays->withdraw($holiday, auth()->user(), $this->reason);
        $this->reason = '';
    }

    public function differences(): array
    {
        $previous = null;
        $changes = [];
        foreach ($this->holiday()?->versions->sortBy('version') ?? [] as $version) {
            $fields = [];
            foreach (['name_en', 'name_ar', 'ranges', 'legal_entities', 'branch_ids', 'work_patterns',
                'exceptions_note', 'status', 'source_id'] as $field) {
                if ($previous !== null && $previous->{$field} !== $version->{$field}) {
                    $fields[$field] = ['before' => $previous->{$field}, 'after' => $version->{$field}];
                }
            }
            $changes[$version->id] = $fields;
            $previous = $version;
        }

        return $changes;
    }

    public function affectedDays(): int
    {
        return $this->holidayId === null ? 0 : Day::query()->where('company_id', $this->company()->id)
            ->whereNull('superseded_by_run_id')->whereJsonContains('holiday_ids', $this->holidayId)->count();
    }

    public function displayValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : (string) $value;
    }

    private function validateForm(bool $approved): void
    {
        $this->validate([
            'nameEn' => 'required|string|max:255', 'nameAr' => 'nullable|string|max:255',
            'ranges' => ($approved ? 'required' : 'nullable').'|array',
            'ranges.*.from' => 'required|date_format:Y-m-d',
            'ranges.*.to' => 'required|date_format:Y-m-d',
            'legalEntities' => 'array', 'legalEntities.*' => 'string',
            'branchIds' => 'array', 'branchIds.*' => 'integer',
            'workPatterns' => 'array', 'workPatterns.*' => 'in:office,shift,security',
            'exceptionsNote' => 'nullable|string',
        ]);
    }

    private function requireReason(): void
    {
        $this->validate(['reason' => 'required|string|max:500']);
    }

    private function values(): array
    {
        return [
            'name_en' => $this->nameEn, 'name_ar' => $this->nameAr === '' ? null : $this->nameAr,
            'ranges' => array_values($this->ranges), 'legal_entities' => array_values($this->legalEntities),
            'branch_ids' => array_values($this->branchIds), 'work_patterns' => array_values($this->workPatterns),
            'exceptions_note' => $this->exceptionsNote === '' ? null : $this->exceptionsNote,
            'source_id' => $this->sourceId,
        ];
    }
}
