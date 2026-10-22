<x-filament-panels::page>
    <div class="space-y-6">
        <section class="overflow-x-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"><table class="w-full text-start"><thead><tr>
            @foreach (['serial', 'name', 'state', 'role', 'site', 'first', 'last', 'actions'] as $column)<th class="p-2">{{ __('messages.attendance_ui.'.$column) }}</th>@endforeach
        </tr></thead><tbody>
            @foreach ($this->devices() as $device)
                @php $details = $this->details($device); @endphp
                <tr class="border-t" wire:key="attendance-device-{{ $device->id }}"><td class="p-2" dir="ltr">{{ $device->serial_number }}</td><td class="p-2">{{ $device->name }}</td>
                    <td class="p-2">{{ __('messages.attendance_ui.device_states.'.$device->status) }}</td>
                    <td class="p-2">{{ $details['setting'] ? __('messages.attendance_ui.roles.'.$details['setting']->role) : ($device->suggested_role ? __('messages.attendance_ui.roles.'.$device->suggested_role) : '—') }}</td>
                    <td class="p-2">{{ $details['setting']?->branch?->name ?? $device->suggestedBranch?->name ?? '—' }}</td>
                    <td class="p-2" dir="ltr">{{ $details['first'] }}</td><td class="p-2" dir="ltr">{{ $details['last'] }}</td>
                    <td class="p-2">@if ($device->status === 'pending') <button class="underline" wire:click="selectDevice({{ $device->id }}, &quot;approve&quot;)">{{ __('messages.attendance_ui.approve') }}</button> @endif
                        @if ($device->status === 'approved') <button class="underline" wire:click="selectDevice({{ $device->id }}, &quot;change&quot;)">{{ __('messages.attendance_ui.change') }}</button> <button class="underline" wire:click="selectDevice({{ $device->id }}, &quot;retire&quot;)">{{ __('messages.attendance_ui.retire') }}</button> @endif
                    </td></tr>
            @endforeach
        </tbody></table>{{ $this->devices()->links() }}</section>
        @if ($selectedDevice !== null)
            <form wire:submit="save" class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('messages.attendance_ui.'.$action) }} <bdi dir="ltr">#{{ $selectedDevice }}</bdi></h2>
                @if ($action !== 'retire')
                    <label>{{ __('messages.attendance_ui.role') }} <select wire:model="role" class="rounded-xl border border-gray-200 p-2">@foreach (['check_in', 'check_out', 'restaurant'] as $option)<option value="{{ $option }}">{{ __('messages.attendance_ui.roles.'.$option) }}</option>@endforeach</select></label>
                    <label>{{ __('messages.attendance_ui.site') }} <select wire:model="branchId" class="rounded-xl border border-gray-200 p-2"><option value="">—</option>@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                @endif
                <label>{{ __('messages.attendance_ui.effective_from') }} <input type="date" wire:model="effectiveFrom" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                <label>{{ __('messages.attendance_ui.reason') }} <input type="text" wire:model="reason" class="rounded-xl border border-gray-200 p-2"></label>
                @error('role') <p class="text-red-600">{{ $message }}</p> @enderror @error('branchId') <p class="text-red-600">{{ $message }}</p> @enderror @error('effectiveFrom') <p class="text-red-600">{{ $message }}</p> @enderror @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit">{{ __('messages.attendance_ui.save') }}</x-filament::button>
            </form>
        @endif
    </div>
</x-filament-panels::page>
