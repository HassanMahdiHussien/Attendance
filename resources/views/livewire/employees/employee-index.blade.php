<div class="space-y-5">
    <x-ui.page-header :title="__('Employees')" />
    <x-ui.input wire:model.live.debounce.300ms="search" type="search" :placeholder="__('Name, employee number, or department')" :aria-label="__('Search employees')" />
    <div class="space-y-2">
        @forelse($employees as $employee)
            <x-ui.card padding="none">
                <a href="{{ route('employees.show', $employee) }}" class="block rounded-2xl p-4 hover:bg-primary-light focus-visible:outline-2 focus-visible:outline-primary">
                    <strong class="text-ink">{{ $employee->name }}</strong>
                    <span class="ms-3 text-sm text-gray-500"><bdi>{{ \App\Support\Format\Ui::number($employee->employee_code) }}</bdi> · {{ $employee->department }} · {{ $employee->branch_name }}</span>
                </a>
            </x-ui.card>
        @empty
            <x-ui.card><x-ui.empty :title="__('No matching employees.')" /></x-ui.card>
        @endforelse
    </div>
    <div>{{ $employees->links() }}</div>
</div>
