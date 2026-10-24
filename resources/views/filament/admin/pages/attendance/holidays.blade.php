<x-filament-panels::page>
    <div class="space-y-5">
        <div class="flex flex-wrap gap-3">
            <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\UploadHoliday::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.holiday_ui.upload') }}</a>
            <a class="ui-link" href="{{ \App\Filament\Admin\Pages\Attendance\HolidayReview::getUrl(panel: 'admin', tenant: $this->company()) }}">{{ __('messages.holiday_ui.new') }}</a>
        </div>
        <x-ui.card>
            <div class="flex flex-wrap gap-4">
                <label>{{ __('messages.holiday_ui.status') }} <select wire:model.live="status" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.holiday_ui.all') }}</option>@foreach (['draft', 'approved', 'rejected', 'withdrawn'] as $state)<option value="{{ $state }}">{{ __('messages.holiday_ui.statuses.'.$state) }}</option>@endforeach</select></label>
                <label>{{ __('messages.holiday_ui.year') }} <input type="number" min="2000" max="2100" wire:model.live="year" dir="ltr" class="w-28 rounded-xl border border-gray-200 p-2"></label>
            </div>
        </x-ui.card>
        <x-ui.card padding="none">
            <div class="overflow-x-auto"><table class="w-full text-start text-sm"><thead><tr>@foreach (['name', 'dates', 'scope', 'status', 'version', 'source'] as $column)<th class="p-3">{{ __('messages.holiday_ui.'.$column) }}</th>@endforeach</tr></thead><tbody>
                @forelse ($this->holidays() as $holiday)
                    <tr class="border-t" wire:key="holiday-{{ $holiday->id }}">
                        <td class="p-3"><a class="ui-link" href="{{ $this->detailUrl($holiday) }}">{{ app()->getLocale() === 'ar' ? ($holiday->currentVersion?->name_ar ?: $holiday->name) : $holiday->name }}</a></td>
                        <td class="p-3">@foreach ($holiday->currentVersion?->ranges ?? [] as $range)<bdi dir="ltr" class="block">{{ $range['from'] }} – {{ $range['to'] }}</bdi>@endforeach</td>
                        <td class="p-3">{{ $this->scopeLabel($holiday->currentVersion?->legal_entities ?? [], $holiday->currentVersion?->branch_ids ?? [], $holiday->currentVersion?->work_patterns ?? []) }}</td>
                        <td class="p-3">{{ __('messages.holiday_ui.statuses.'.$holiday->status) }}</td>
                        <td class="p-3" dir="ltr">{{ $holiday->currentVersion?->version }}</td>
                        <td class="p-3">@if ($source = $holiday->currentVersion?->source)<bdi dir="ltr">{{ $source->file_name }}</bdi><span class="block">{{ __('messages.holiday_ui.ai_statuses.'.$source->ai_status) }}</span>@else {{ __('messages.holiday_ui.manual') }} @endif</td>
                    </tr>
                @empty <tr><td colspan="6" class="p-5">{{ __('messages.holiday_ui.none') }}</td></tr> @endforelse
            </tbody></table></div>
            <div class="p-3">{{ $this->holidays()->links() }}</div>
        </x-ui.card>
    </div>
</x-filament-panels::page>
