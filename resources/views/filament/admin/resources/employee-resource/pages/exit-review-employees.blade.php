<x-filament-panels::page>
    <div class="space-y-4">
        @forelse ($this->pendingEmployees() as $employee)
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm" wire:key="exit-{{ $employee->id }}">
                <h2 class="font-bold">{{ $employee->name }} · <bdi dir="ltr">{{ $employee->employee_code }}</bdi></h2>
                @if ($employee->exitReviewBatch)
                    <p>{{ __('Raised by upload') }}: <bdi dir="ltr">{{ $employee->exitReviewBatch->created_at?->toDateString() }}</bdi></p>
                @endif
                @if ($employee->user)
                    <p class="text-amber-700">{{ __('This employee has a linked user account.') }}</p>
                @endif
                <div class="flex flex-wrap items-end gap-3">
                    <label>{{ __('Last working day') }} <input type="date" wire:model="lastWorkingDays.{{ $employee->id }}" class="rounded border border-gray-300 p-2" dir="ltr"></label>
                    <x-filament::button wire:click="confirm({{ $employee->id }})">{{ __('Confirm exit') }}</x-filament::button>
                    @error('lastWorkingDays.'.$employee->id) <p class="text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap items-end gap-3">
                    <label>{{ __('Dismissal reason') }} <input type="text" wire:model="dismissReasons.{{ $employee->id }}" class="rounded border border-gray-300 p-2"></label>
                    <x-filament::button color="gray" wire:click="dismiss({{ $employee->id }})">{{ __('Dismiss review') }}</x-filament::button>
                    @error('dismissReasons.'.$employee->id) <p class="text-red-600">{{ $message }}</p> @enderror
                </div>
            </section>
        @empty
            <p>{{ __('No employees need exit review.') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
