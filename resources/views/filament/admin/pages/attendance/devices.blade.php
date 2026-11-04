<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.list_context.devices') }}</p>
    @php $devices = $this->devices(); $waiting = $this->pendingCount(); @endphp
    @if ($waiting > 0)<p class="attendance-device-alert">{{ __('messages.attendance_ui.devices_waiting', ['count' => $waiting]) }}</p>@endif
    <div class="space-y-6">
        @if ($selectedDevice !== null)
            <form wire:submit="save" class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.attendance_ui.'.$action) }} <bdi dir="ltr">#{{ $selectedDevice }}</bdi></h2>
                @if ($action !== 'retire')
                    <label>{{ __('messages.attendance_ui.role') }} <select wire:model="role" class="rounded-xl border border-gray-200 p-2"><option value="">{{ __('messages.attendance_ui.choose_role') }}</option>@foreach (['check_in', 'check_out', 'check_in_out', 'restaurant'] as $option)<option value="{{ $option }}">{{ __('messages.attendance_ui.roles.'.$option) }}</option>@endforeach</select></label>
                    <label>{{ __('messages.attendance_ui.site') }} <select wire:model="branchId" class="rounded-xl border border-gray-200 p-2"><option value="">—</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                @endif
                <label>{{ __('messages.attendance_ui.effective_from') }} <input type="date" wire:model="effectiveFrom" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                @if ($action === 'change')<button type="button" class="ui-button-secondary" wire:click="useFirstPunchDate">{{ __('messages.attendance_ui.use_first_punch_date') }}</button>@endif
                <label>{{ __('messages.attendance_ui.reason') }} <input type="text" wire:model="reason" class="rounded-xl border border-gray-200 p-2"></label>
                @error('role') <p class="text-red-600">{{ $message }}</p> @enderror @error('branchId') <p class="text-red-600">{{ $message }}</p> @enderror @error('effectiveFrom') <p class="text-red-600">{{ $message }}</p> @enderror @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit">{{ __('messages.attendance_ui.save') }}</x-filament::button>
            </form>
        @endif
        <section class="overflow-x-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"><x-ui.table><thead><tr>
            @foreach (['name', 'serial', 'state', 'site', 'role', 'first', 'last', 'punches', 'people', 'actions'] as $column)<th class="p-2">{{ __('messages.attendance_ui.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
            @forelse ($devices as $device)
                @php $details = $this->details($device); @endphp
                <tr class="border-t" wire:key="attendance-device-{{ $device->id }}"><x-ui.td :label="__('messages.attendance_ui.name')">{{ $device->name }}</x-ui.td><x-ui.td :label="__('messages.attendance_ui.serial')"><bdi dir="ltr">{{ $device->serial_number }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.state')"><x-ui.badge :tone="match ($device->status) { 'pending' => 'indigo', 'approved' => 'green', default => 'gray' }">{{ __('messages.attendance_ui.device_states.'.$device->status) }}</x-ui.badge></x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.role')">{{ $details['setting'] ? __('messages.attendance_ui.roles.'.$details['setting']->role) : ($device->suggested_role ? __('messages.attendance_ui.roles.'.$device->suggested_role) : '—') }}</x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.site')">{{ $details['setting']?->branch?->name ?? $device->suggestedBranch?->name ?? '—' }}</x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.first')"><bdi dir="ltr">{{ $details['first'] ?? '—' }}</bdi></x-ui.td><x-ui.td :label="__('messages.attendance_ui.last')"><bdi dir="ltr">{{ $details['last'] ?? '—' }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.punches')"><bdi dir="ltr">{{ $details['punches'] }}</bdi></x-ui.td><x-ui.td :label="__('messages.attendance_ui.people')"><bdi dir="ltr">{{ $details['people'] }}</bdi></x-ui.td>
                    <x-ui.td :label="__('messages.attendance_ui.actions')">@if ($device->status === 'pending') <button class="underline" wire:click="selectDevice({{ $device->id }}, &quot;approve&quot;)">{{ __('messages.attendance_ui.approve') }}</button> @endif
                        @if ($device->status === 'approved') <button class="underline" wire:click="selectDevice({{ $device->id }}, &quot;change&quot;)">{{ __('messages.attendance_ui.change') }}</button> <button class="underline" wire:click="selectDevice({{ $device->id }}, &quot;retire&quot;)">{{ __('messages.attendance_ui.retire') }}</button> @endif
                    </x-ui.td></tr>
            @empty <tr><td colspan="10" class="p-4"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
        </tbody></x-ui.table>{{ $devices->links() }}</section>
    </div>
</x-filament-panels::page>
