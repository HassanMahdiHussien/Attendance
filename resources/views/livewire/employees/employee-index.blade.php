<div class="space-y-5">
    <x-ui.page-header :title="__('Employees')">
        <x-slot:actions>
            @if($addUrl)<x-ui.button :href="$addUrl">{{ __('Add employee') }}</x-ui.button>@endif
            @if($importUrl)<x-ui.button variant="secondary" :href="$importUrl">{{ __('Import CSV') }}</x-ui.button>@endif
        </x-slot:actions>
    </x-ui.page-header>
    @if($companyChoices)
        <label class="block text-sm font-semibold" for="employee-company">{{ __('Company') }}</label>
        <x-ui.select id="employee-company" wire:model.live="companyId"><option value="">{{ __('Choose a company') }}</option>@foreach($companyChoices as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-ui.select>
    @endif
    <x-ui.input wire:model.live.debounce.300ms="search" type="search" :placeholder="__('Name, employee number, or department')" :aria-label="__('Search employees')" />
    <x-ui.table>
        <thead><tr><th scope="col">{{ __('Employees') }}</th><th scope="col">{{ __('messages.office_ui.branch') }}</th><th scope="col">{{ __('Department') }}</th><th scope="col">{{ __('Edit') }}</th></tr></thead>
        <tbody>
        @forelse($employees as $employee)
            <tr>
                <x-ui.td :label="__('Employees')"><a href="{{ route('employees.show', $employee) }}" class="ui-link font-semibold">{{ $employee->name }}</a><span class="block text-xs"><bdi dir="ltr">{{ \App\Support\Format\Ui::number($employee->employee_code) }}</bdi></span></x-ui.td>
                <x-ui.td :label="__('messages.office_ui.branch')">{{ $employee->branch_name ?? '—' }}</x-ui.td>
                <x-ui.td :label="__('Department')">{{ $employee->department ?? '—' }}</x-ui.td>
                <x-ui.td :label="__('Edit')">@if(isset($editUrls[$employee->id]))<a href="{{ $editUrls[$employee->id] }}" class="ui-link">{{ __('Edit') }}</a>@else — @endif</x-ui.td>
            </tr>
        @empty <tr><td colspan="4" class="p-4"><x-ui.empty :title="__('No matching employees.')" /></td></tr> @endforelse
        </tbody>
    </x-ui.table>
    <div>{{ $employees->links() }}</div>
</div>
