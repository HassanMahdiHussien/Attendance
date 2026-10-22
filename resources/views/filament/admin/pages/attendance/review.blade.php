<x-filament-panels::page>
    <div class="space-y-5">
        <x-ui.card><div class="flex flex-wrap gap-4">
            <label>{{ __('messages.office_ui.branch') }} <select wire:model.live="branchId" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.office_ui.all') }}</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label>{{ __('messages.office_ui.from') }} <input type="date" wire:model.live="from" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.office_ui.to') }} <input type="date" wire:model.live="to" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
            <label>{{ __('messages.office_ui.review_state') }} <select wire:model.live="reviewState" class="rounded-xl border border-gray-200 p-2"><option value="awaiting">{{ __('messages.office_ui.awaiting') }}</option><option value="reviewed">{{ __('messages.office_ui.reviewed_days') }}</option></select></label>
        </div></x-ui.card>
        <x-ui.card>
            <div class="space-y-3">
                @forelse ($this->days() as $day)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b p-2" wire:key="office-review-{{ $day->id }}">
                        <div><a href="{{ $this->detailUrl($day) }}" class="font-semibold text-primary underline">{{ $day->employee->name }}</a> <bdi class="font-mono text-xs" dir="ltr">{{ $day->employee->employee_code }}</bdi><br><bdi dir="ltr">{{ $day->work_date->toDateString() }}</bdi> · {{ $day->branch?->name }} · <x-ui.badge :tone="$day->status === 'absent' ? 'red' : 'amber'">{{ __('messages.office_ui.statuses.'.$day->status) }}</x-ui.badge></div>
                        @if ($reviewState === 'awaiting')
                            <button class="underline" wire:click="selectReviewDay({{ $day->id }})">{{ __('messages.office_ui.review_action') }}</button>
                        @else
                            @php $latest = $this->latestReview($day); @endphp
                            @if ($latest)
                                <button class="underline" wire:click="selectReviewDay({{ $day->id }})">{{ __('messages.office_ui.withdraw') }}</button>
                                @if ($reviewDayId === $day->id)
                                    <button class="underline" wire:click="withdrawLatest({{ $day->id }})">{{ __('messages.office_ui.confirm_withdraw') }}</button>
                                @endif
                            @endif
                        @endif
                    </div>
                @empty <p>{{ __('messages.office_ui.no_reviews') }}</p> @endforelse
                {{ $this->days()->links() }}
            </div>
        </x-ui.card>
        @if ($reviewDayId !== null)
            <x-ui.card :title="__('messages.office_ui.review_action')">
                <div class="flex flex-wrap gap-3">
                    @if ($reviewState === 'awaiting')
                        <label>{{ __('messages.office_ui.role') }} <select wire:model="reviewRole" class="rounded-xl border border-gray-200 p-2"><option value="check_in">{{ __('messages.office_ui.check_in') }}</option><option value="check_out">{{ __('messages.office_ui.check_out') }}</option></select></label>
                        <label>{{ __('messages.office_ui.time') }} <input type="time" wire:model="reviewTime" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                    @endif
                    <label>{{ __('messages.office_ui.reason') }} <input type="text" wire:model="reviewReason" class="rounded-xl border border-gray-200 p-2"></label>
                </div>
                @error('reviewRole') <p class="text-red-600">{{ $message }}</p> @enderror @error('reviewTime') <p class="text-red-600">{{ $message }}</p> @enderror @error('reviewReason') <p class="text-red-600">{{ $message }}</p> @enderror
                @if ($reviewState === 'awaiting')
                    <div class="mt-3 flex gap-3"><x-filament::button wire:click="addMissingTime">{{ __('messages.office_ui.add_time') }}</x-filament::button><button class="underline" wire:click="confirmAbsence">{{ __('messages.office_ui.confirm_absence') }}</button></div>
                @endif
            </x-ui.card>
        @endif
    </div>
</x-filament-panels::page>
