<div>
    <h1 class="text-2xl font-black mb-6">الموظفون</h1>
    <input wire:model.live.debounce.300ms="search" type="search" placeholder="الاسم أو الرقم الوظيفي أو القسم" class="w-full rounded-xl border border-gray-200 p-3 mb-5" aria-label="بحث الموظفين">
    <div class="space-y-2">
        @forelse($employees as $employee)
            <a href="{{ route('employees.show', $employee) }}" class="block bg-white rounded-xl p-4 hover:shadow">
                <strong>{{ $employee->name }}</strong><span class="text-gray-500 mr-3">{{ \App\Support\Format\ArabicDigits::convert($employee->employee_code) }} · {{ $employee->department }} · {{ $employee->branch_name }}</span>
            </a>
        @empty
            <p class="bg-white rounded-xl p-5 text-gray-500">لا يوجد موظفون مطابقون.</p>
        @endforelse
    </div>
    <div class="mt-5">{{ $employees->links() }}</div>
</div>
