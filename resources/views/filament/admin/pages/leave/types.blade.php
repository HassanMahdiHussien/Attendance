<x-filament-panels::page>
    <p class="text-sm text-gray-600">{{ __('messages.clear_screens.leave_types_context') }}</p>
    <x-ui.card :title="__('messages.leave_ui.types_title')">
        <x-ui.table><thead><tr>@foreach (['type', 'other_type', 'covers', 'consumes', 'mapped', 'actions'] as $column)<th>{{ __('messages.leave_ui.'.$column) }}</th>@endforeach</tr></thead><tbody>
            @forelse ($this->types() as $type)
                <tr class="border-t" wire:key="leave-type-{{ $type->id }}"><x-ui.td :label="__('messages.leave_ui.type')">{{ $type->name }}</x-ui.td><x-ui.td :label="__('messages.leave_ui.other_type')">{{ $type->other_name ?? '—' }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.covers')">{{ $type->covers ? __('messages.leave_ui.yes') : __('messages.leave_ui.no') }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.consumes')">{{ __('messages.leave_ui.consumes_values.'.$type->consumes) }}</x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.mapped')"><x-ui.badge :tone="$type->is_mapped ? 'green' : 'indigo'">{{ $type->is_mapped ? __('messages.leave_ui.yes') : __('messages.leave_ui.no') }}</x-ui.badge></x-ui.td>
                    <x-ui.td :label="__('messages.leave_ui.actions')"><button class="ui-link" wire:click="selectType({{ $type->id }})">{{ __('messages.leave_ui.edit') }}</button></x-ui.td></tr>
            @empty <tr><td colspan="6"><x-ui.empty :title="__('messages.list_empty')" /></td></tr> @endforelse
        </tbody></x-ui.table>
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
