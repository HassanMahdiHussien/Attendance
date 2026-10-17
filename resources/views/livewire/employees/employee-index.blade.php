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
    <div class="space-y-2">
        @forelse($employees as $employee)
            <x-ui.card padding="none">
                <a href="{{ route('employees.show', $employee) }}" class="block rounded-2xl p-4 hover:bg-primary-light focus-visible:outline-2 focus-visible:outline-primary">
                    <strong class="text-ink">{{ $employee->name }}</strong>
                    <span class="ms-3 text-sm text-gray-500"><bdi>{{ \App\Support\Format\Ui::number($employee->employee_code) }}</bdi> · {{ $employee->department }} · {{ $employee->branch_name }}</span>
                </a>
                @if(isset($editUrls[$employee->id]))<a href="{{ $editUrls[$employee->id] }}" class="ui-link mx-4 mb-3 inline-block text-sm">{{ __('Edit') }}</a>@endif
            </x-ui.card>
        @empty
            <x-ui.card><x-ui.empty :title="__('No matching employees.')" /></x-ui.card>
        @endforelse
    </div>
    <div>{{ $employees->links() }}</div>
</div>
