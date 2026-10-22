<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Models\Leave\LeaveType;
use App\Services\Leave\LeaveTypes;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LeaveTypesPage extends LeavePage
{
    protected static ?string $slug = 'leave/types';

    protected string $view = 'filament.admin.pages.leave.types';

    public ?int $selectedType = null;

    public bool $covers = false;

    public string $consumes = 'none';

    public string $reason = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_ui.types_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function types(): LengthAwarePaginator
    {
        return LeaveType::query()->where('company_id', $this->company()->id)
            ->orderBy('is_mapped')->orderBy('name')->paginate(25);
    }

    public function selectType(int $typeId): void
    {
        $type = LeaveType::query()->where('company_id', $this->company()->id)->findOrFail($typeId);
        $this->selectedType = $type->id;
        $this->covers = $type->covers;
        $this->consumes = $type->consumes;
        $this->reason = '';
    }

    public function save(LeaveTypes $types): void
    {
        $type = LeaveType::query()->where('company_id', $this->company()->id)->findOrFail($this->selectedType);
        $this->validate(['consumes' => 'required|in:none,rest_day_entitlement,overtime_or_credit', 'reason' => 'required|string|max:2000']);
        $types->map($type, $this->covers, $this->consumes, $this->reason, auth()->user());
        $this->selectedType = null;
        Notification::make()->title(__('messages.leave_ui.saved'))->success()->send();
    }
}
