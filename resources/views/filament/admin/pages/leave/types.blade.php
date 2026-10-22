<x-filament-panels::page>
    <x-ui.card :title="__('messages.leave_ui.types_title')">
        <div class="overflow-x-auto"><table class="w-full text-start"><thead><tr>@foreach (['type', 'other_type', 'covers', 'consumes', 'mapped', 'actions'] as $column)<th class="p-2">{{ __('messages.leave_ui.'.$column) }}</th>@endforeach</tr></thead><tbody>
            @foreach ($this->types() as $type)
                <tr class="border-t" wire:key="leave-type-{{ $type->id }}"><td class="p-2">{{ $type->name }}</td><td class="p-2">{{ $type->other_name ?? '—' }}</td>
                    <td class="p-2">{{ $type->covers ? __('messages.leave_ui.yes') : __('messages.leave_ui.no') }}</td>
                    <td class="p-2">{{ __('messages.leave_ui.consumes_values.'.$type->consumes) }}</td>
                    <td class="p-2">{{ $type->is_mapped ? __('messages.leave_ui.yes') : __('messages.leave_ui.no') }}</td>
                    <td class="p-2"><button class="underline" wire:click="selectType({{ $type->id }})">{{ __('messages.leave_ui.edit') }}</button></td></tr>
            @endforeach
        </tbody></table></div>
        {{ $this->types()->links() }}
    </x-ui.card>
    @if ($selectedType !== null)
        <x-ui.card :title="__('messages.leave_ui.edit_mapping')">
            <form wire:submit="save" class="flex flex-wrap items-end gap-3">
                <label><input type="checkbox" wire:model="covers"> {{ __('messages.leave_ui.covers') }}</label>
                <label>{{ __('messages.leave_ui.consumes') }} <select wire:model="consumes" class="rounded-xl border border-gray-200 p-2">@foreach (['none', 'rest_day_entitlement', 'overtime_or_credit'] as $value)<option value="{{ $value }}">{{ __('messages.leave_ui.consumes_values.'.$value) }}</option>@endforeach</select></label>
                <label>{{ __('messages.leave_ui.reason') }} <input type="text" wire:model="reason" class="rounded-xl border border-gray-200 p-2"></label>
                <x-filament::button type="submit">{{ __('messages.leave_ui.save') }}</x-filament::button>
            </form>
            @error('reason') <p class="text-red-600">{{ $message }}</p> @enderror
            @error('consumes') <p class="text-red-600">{{ $message }}</p> @enderror
        </x-ui.card>
    @endif
</x-filament-panels::page>
