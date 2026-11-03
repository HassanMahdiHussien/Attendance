<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.exit_review') }}</p>
    <div class="space-y-4">
        <x-ui.table>
            <thead><tr><th scope="col">{{ __('Employees') }}</th><th scope="col">{{ __('Raised by upload') }}</th><th scope="col">{{ __('Last working day') }}</th><th scope="col">{{ __('Dismiss review') }}</th></tr></thead>
            <tbody>
            @forelse ($this->pendingEmployees() as $employee)
                <tr wire:key="exit-{{ $employee->id }}">
                    <x-ui.td :label="__('Employees')"><strong>{{ $employee->name }}</strong><span class="block"><bdi dir="ltr">{{ $employee->employee_code }}</bdi></span>@if ($employee->user)<p class="text-amber-700">{{ __('This employee has a linked user account.') }}</p>@endif</x-ui.td>
                    <x-ui.td :label="__('Raised by upload')">{{ $employee->exitReviewBatch?->created_at?->toDateString() ?? '—' }}</x-ui.td>
                    <x-ui.td :label="__('Last working day')"><label>{{ __('Last working day') }} <input type="date" wire:model="lastWorkingDays.{{ $employee->id }}" class="ui-control max-w-full" dir="ltr"></label><button class="ui-button ui-button-secondary ui-button-small" wire:click="confirm({{ $employee->id }})">{{ __('Confirm exit') }}</button>@error('lastWorkingDays.'.$employee->id) <p class="text-red-600">{{ $message }}</p> @enderror</x-ui.td>
                    <x-ui.td :label="__('Dismiss review')"><label>{{ __('Dismissal reason') }} <input type="text" wire:model="dismissReasons.{{ $employee->id }}" class="ui-control max-w-full"></label><button class="ui-button ui-button-ghost ui-button-small" wire:click="dismiss({{ $employee->id }})">{{ __('Dismiss review') }}</button>@error('dismissReasons.'.$employee->id) <p class="text-red-600">{{ $message }}</p> @enderror</x-ui.td>
                </tr>
            @empty <tr><td colspan="4" class="p-4"><x-ui.empty :title="__('No employees need exit review.')" /></td></tr> @endforelse
            </tbody>
        </x-ui.table>
    </div>
</x-filament-panels::page>
