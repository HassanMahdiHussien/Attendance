<x-filament-panels::page>
    @php $presentations = $this->dayPresentations(); @endphp
    <div class="space-y-5">
        <p class="text-sm text-gray-600">{{ __('messages.office_ui.review_state') }}: {{ $reviewState === 'awaiting' ? __('messages.office_ui.awaiting') : ($reviewState === 'needs_shift' ? __('messages.office_ui.needs_shift') : __('messages.office_ui.reviewed_days')) }} · <bdi dir="ltr">{{ $from }} – {{ $to }}</bdi></p>
        <details wire:ignore.self class="rounded-xl border border-line bg-white p-4" @if($branchId !== '' || $reviewState !== 'awaiting' || $from !== today('Asia/Baghdad')->subDays(30)->toDateString() || $to !== today('Asia/Baghdad')->toDateString()) open @endif><summary class="cursor-pointer font-semibold">{{ __('messages.clear_screens.more_filters') }}</summary>
        <x-ui.card><div class="flex flex-wrap gap-4">
            <label>{{ __('messages.office_ui.branch') }} <select wire:model.live="branchId" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.office_ui.all') }}</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('messages.office_ui.from') }} <input type="date" wire:model.live="from" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.office_ui.to') }} <input type="date" wire:model.live="to" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.office_ui.review_state') }} <select wire:model.live="reviewState" class="rounded-xl border border-gray-200 p-2"><option value="awaiting">{{ __('messages.office_ui.awaiting') }}</option><option value="needs_shift">{{ __('messages.office_ui.needs_shift') }}</option><option value="reviewed">{{ __('messages.office_ui.reviewed_days') }}</option></select></label>
        </div></x-ui.card></details>
        <x-ui.card>
            <div class="space-y-3">
                <x-ui.table>
                    <thead><tr><th scope="col">{{ __('messages.office_ui.employee') }}</th><th scope="col">{{ __('messages.office_ui.date') }}</th><th scope="col">{{ __('messages.office_ui.status') }}</th><th scope="col">{{ __('messages.office_ui.branch') }}</th><th scope="col">{{ __('messages.office_ui.review_action') }}</th></tr></thead>
                    <tbody>
                    @forelse ($this->days() as $day)
                        @php $presentation = $presentations[$day->id] ?? null; @endphp
                        <tr wire:key="office-review-{{ $day->id }}">
                            <x-ui.td :label="__('messages.office_ui.employee')"><a href="{{ $this->detailUrl($day) }}" class="font-semibold text-primary underline">{{ $day->employee->name }}</a> <bdi class="font-mono text-xs" dir="ltr">{{ $day->employee->employee_code }}</bdi></x-ui.td>
                            <x-ui.td :label="__('messages.office_ui.date')">{{ $presentation['weekday'] ?? $day->work_date->translatedFormat('l') }} {{ $day->work_date->translatedFormat('j F Y') }}</x-ui.td>
                            <x-ui.td :label="__('messages.office_ui.status')"><x-ui.badge :tone="$presentation['tone'] ?? 'gray'">{{ $presentation['label'] ?? __('messages.office_ui.statuses.'.$day->status) }}</x-ui.badge></x-ui.td>
                            <x-ui.td :label="__('messages.office_ui.branch')">{{ $day->branch?->name ?? '—' }}</x-ui.td>
                            <x-ui.td :label="__('messages.office_ui.review_action')">
                                @if (in_array($reviewState, ['awaiting', 'needs_shift'], true))
                                    <button class="ui-link" wire:click="selectReviewDay({{ $day->id }})">{{ __('messages.office_ui.review_action') }}</button>
                                @else
                                    @php $latest = $this->latestReview($day); @endphp
                                    @if ($latest)
                                        <button class="ui-link" wire:click="selectReviewDay({{ $day->id }})">{{ __('messages.office_ui.withdraw') }}</button>
                                        @if ($reviewDayId === $day->id)
                                            <button class="ui-link" wire:click="withdrawLatest({{ $day->id }})">{{ ($latest['shift'] ?? false) ? __('messages.office_ui.withdraw_shift') : __('messages.office_ui.confirm_withdraw') }}</button>
                                        @endif
                                    @endif
                                @endif
                            </x-ui.td>
                        </tr>
                    @empty <tr><td colspan="5" class="p-4">{{ __('messages.office_ui.no_reviews') }}</td></tr> @endforelse
                    </tbody>
                </x-ui.table>
                {{ $this->days()->links() }}
            </div>
        </x-ui.card>
        @if ($reviewDayId !== null)
            <x-ui.card :title="__('messages.office_ui.review_action')">
                <div class="flex flex-wrap gap-3">
                    @if (in_array($reviewState, ['awaiting', 'needs_shift'], true))
                        <label>{{ __('messages.office_ui.role') }} <select wire:model="reviewRole" class="rounded-xl border border-gray-200 p-2"><option value="check_in">{{ __('messages.office_ui.check_in') }}</option><option value="check_out">{{ __('messages.office_ui.check_out') }}</option></select></label>
                        <label>{{ __('messages.office_ui.time') }} <input type="time" wire:model="reviewTime" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                        @if ($reviewIsShift)
                            <label>{{ __('messages.office_ui.shift') }} <select wire:model="reviewShiftStart" dir="ltr" class="rounded-xl border border-gray-200 p-2">@foreach ($reviewShiftStarts as $start)<option value="{{ substr($start, 0, 5) }}">{{ substr($start, 0, 5) }}</option>@endforeach</select></label>
                        @endif
                    @endif
                    <label>{{ __('messages.office_ui.reason') }} <input type="text" wire:model="reviewReason" class="rounded-xl border border-gray-200 p-2"></label>
                </div>
                @error('reviewRole') <p class="text-red-600">{{ $message }}</p> @enderror @error('reviewTime') <p class="text-red-600">{{ $message }}</p> @enderror @error('reviewShiftStart') <p class="text-red-600">{{ $message }}</p> @enderror @error('reviewReason') <p class="text-red-600">{{ $message }}</p> @enderror
                @if (in_array($reviewState, ['awaiting', 'needs_shift'], true))
                    <div class="mt-3 flex gap-3"><x-filament::button wire:click="addMissingTime">{{ __('messages.office_ui.add_time') }}</x-filament::button><button class="underline" wire:click="confirmAbsence">{{ __('messages.office_ui.confirm_absence') }}</button>@if ($reviewIsShift)<button class="underline" wire:click="pickShift">{{ __('messages.office_ui.pick_shift') }}</button>@endif</div>
                @endif
            </x-ui.card>
        @endif
    </div>
</x-filament-panels::page>
