{{-- Lets HR act on an open follow-up where it is shown; $model is the Livewire property path for the reason. --}}
@if ($case->state !== 'closed' && auth()->user()->can('sendAttendanceNotifications', $company))
    <div class="mt-3 rounded-lg border border-line bg-gray-50 p-3 text-sm">
        <p class="text-gray-700">{{ $case->state === 'waiting_hr' ? __('messages.follow_up_ui.hr_next_step') : __('messages.follow_up_ui.hr_next_step_open') }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            @if ($case->state === 'waiting_hr' && ($emailUrl ?? null))<a class="ui-button ui-button-secondary ui-button-small" href="{{ $emailUrl }}">{{ __('messages.follow_up_ui.add_email') }}</a>@endif
            <input wire:model="{{ $model }}" class="ui-control min-w-0 flex-1" placeholder="{{ __('messages.follow_up_ui.close_reason_placeholder') }}" aria-label="{{ __('messages.follow_up_ui.reason') }}">
            <button type="button" wire:click="closeFollowUpCase({{ $case->id }})" class="ui-button ui-button-secondary ui-button-small">{{ __('messages.follow_up_ui.close') }}</button>
        </div>
        @error($model) <p class="mt-1 text-red-600">{{ $message }}</p> @enderror
    </div>
@endif
