<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\HolidaySource;
use App\Services\Attendance\Holidays;
use App\Services\Attendance\HolidaySources;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class UploadHoliday extends AttendancePage
{
    use WithFileUploads;

    protected static ?string $slug = 'attendance/holidays/upload';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.attendance.holiday-upload';

    public $sourceFile;

    #[Locked]
    public ?int $sourceId = null;

    public static function getNavigationLabel(): string
    {
        return __('messages.holiday_ui.upload');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $requested = request()->query('source');
        if ($requested !== null) {
            abort_unless(ctype_digit((string) $requested), 404);
            $this->sourceId = HolidaySource::query()->where('company_id', $this->company()->id)
                ->findOrFail((int) $requested)->id;

            return;
        }
        $this->sourceId = HolidaySource::query()->where('company_id', $this->company()->id)
            ->where('uploaded_by', auth()->id())->latest('id')->value('id');
    }

    public function upload(HolidaySources $sources): void
    {
        $this->validate(['sourceFile' => 'required|file|extensions:pdf,png,jpg,jpeg,eml,msg|max:25600']);
        $source = $sources->upload($this->company(), $this->sourceFile, auth()->user());
        $this->sourceId = $source->id;
        $this->sourceFile = null;
    }

    public function source(): ?HolidaySource
    {
        return $this->sourceId === null ? null : HolidaySource::query()
            ->where('company_id', $this->company()->id)->findOrFail($this->sourceId);
    }

    public function createDraft(Holidays $holidays): void
    {
        $source = $this->source();
        abort_unless($source !== null, 404);
        $holiday = $holidays->createFromSource($source, auth()->user());
        $this->redirect(HolidayReview::getUrl(panel: 'admin', tenant: $this->company())
            .'?'.http_build_query(['holiday' => $holiday->id]));
    }
}
