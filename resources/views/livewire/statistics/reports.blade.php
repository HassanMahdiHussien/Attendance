<div class="space-y-6">
    <h1 class="text-2xl font-black">{{ __('Statistical reports') }}</h1>
    <div class="bg-white rounded-2xl p-5 space-y-5">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Report type') }}">
            @foreach(['periodic', 'employee', 'compliance', 'hotspots'] as $key)
                @if(in_array($key, ['periodic', 'hotspots'], true) || $personReportsAvailable || auth()->user()->primary_role !== \App\Enums\UserRole::HrPartnerStaff)
                    <button type="button" wire:click="$set('type', '{{ $key }}')" class="rounded-xl px-4 py-2 {{ $type === $key ? 'bg-primary text-white' : 'bg-gray-100' }}" role="tab" aria-selected="{{ $type === $key ? 'true' : 'false' }}">{{ __('messages.statistics.types.'.$key) }}</button>
                @endif
            @endforeach
        </div>
        <div class="grid sm:grid-cols-3 gap-3">
            @if(in_array(auth()->user()->primary_role, [\App\Enums\UserRole::PlatformAdmin, \App\Enums\UserRole::HrPartnerStaff], true))
                <label class="text-sm">{{ __('Company') }}<select wire:model.live="companyId" class="block w-full rounded-xl border p-2 mt-1"><option value="">{{ auth()->user()->primary_role === \App\Enums\UserRole::PlatformAdmin && in_array($type, ['periodic', 'hotspots'], true) ? __('All companies') : __('Choose a company') }}</option>@foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach</select></label>
            @endif
            <label class="text-sm">{{ __('Period') }}<select wire:model.live="period" class="block w-full rounded-xl border p-2 mt-1"><option value="month">{{ __('Monthly') }}</option><option value="quarter">{{ __('Quarterly') }}</option><option value="custom">{{ __('Custom') }}</option></select></label>
            @if($period !== 'custom')<label class="text-sm">{{ __('Year') }}<input wire:model.live="year" type="number" min="2000" max="2100" class="block w-full rounded-xl border p-2 mt-1"></label>@endif
            @if($period === 'month')<label class="text-sm">{{ __('Month') }}<select wire:model.live="month" class="block w-full rounded-xl border p-2 mt-1">@for($m=1;$m<=12;$m++)<option value="{{ $m }}">{{ \App\Support\Format\Ui::number($m) }}</option>@endfor</select></label>@endif
            @if($period === 'quarter')<label class="text-sm">{{ __('Quarter') }}<select wire:model.live="quarter" class="block w-full rounded-xl border p-2 mt-1">@for($q=1;$q<=4;$q++)<option value="{{ $q }}">{{ \App\Support\Format\Ui::number($q) }}</option>@endfor</select></label>@endif
            @if($period === 'custom')<label class="text-sm">{{ __('From') }}<input wire:model.live="from" type="date" class="block w-full rounded-xl border p-2 mt-1"></label><label class="text-sm">{{ __('To') }}<input wire:model.live="to" type="date" class="block w-full rounded-xl border p-2 mt-1"></label>@endif
            @if($branches->isNotEmpty())<label class="text-sm">{{ __('Branch') }}<select wire:model.live="branch" class="block w-full rounded-xl border p-2 mt-1"><option value="">{{ __('All branches') }}</option>@foreach($branches as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</select></label>@endif
            @if($type === 'employee')<label class="text-sm">{{ __('Employee') }}<select wire:model.live="employeeId" class="block w-full rounded-xl border p-2 mt-1"><option value="">{{ __('Choose an employee') }}</option>@foreach($employees as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->employee_code }}</option>@endforeach</select></label>@endif
        </div>
        @foreach($errors->all() as $error)<p class="text-red text-sm">{{ $error }}</p>@endforeach
        <div class="flex flex-wrap gap-2"><button wire:click="preview" class="bg-primary text-white rounded-xl px-5 py-2">{{ __('View report') }}</button><button wire:click="export('pdf')" class="border border-primary text-primary rounded-xl px-5 py-2">{{ __('Export PDF') }}</button><button wire:click="export('xlsx')" class="border border-primary text-primary rounded-xl px-5 py-2">{{ __('Export Excel') }}</button></div>
    </div>
    @if($preview)<section class="bg-white rounded-2xl p-5 space-y-4"><h2 class="text-xl font-black">{{ $preview['title'] }}</h2><p class="text-gray-500">{{ __('Through the end of the period') }} · {{ \App\Support\Format\Ui::date($preview['parameters']['from']) }} — {{ \App\Support\Format\Ui::date($preview['parameters']['to']) }}</p>
        @if($preview['partial'])<p class="bg-amber-50 p-3 rounded-xl">{{ __('There may be other cases you cannot see') }}</p>@endif
        @if($preview['truncated'])<p class="bg-amber-50 p-3 rounded-xl">{{ __('The first 1,000 rows are shown — use Excel for the full list') }}</p>@endif
        <div class="grid sm:grid-cols-3 gap-3">@foreach($preview['summary'] as $label => $value)<div class="rounded-xl bg-gray-50 p-3"><p class="text-gray-500 text-sm">{{ $label }}</p><strong>{{ is_numeric($value) ? \App\Support\Format\Ui::number($value) : ($value ?? '—') }}</strong></div>@endforeach</div>
        @foreach($preview['sections'] as $section)<h3 class="font-bold">{{ $section['title'] }}</h3><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr>@foreach($section['headers'] as $header)<th class="text-start p-2 bg-gray-100">{{ $header }}</th>@endforeach</tr></thead><tbody>@forelse($section['rows'] as $row)<tr>@foreach($row as $cell)<td class="p-2 border-b">{{ is_numeric($cell) ? \App\Support\Format\Ui::number($cell) : ($cell ?? '—') }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($section['headers']) }}" class="p-3">{{ __('No data for this period.') }}</td></tr>@endforelse</tbody></table></div>@endforeach
    </section>@endif
    <section class="bg-white rounded-2xl p-5" wire:poll.5s><h2 class="text-xl font-black mb-3">{{ __('My exports') }}</h2><div class="space-y-2">@forelse($exports as $export)<div class="flex justify-between gap-3 border-b p-2"><span>{{ __('messages.statistics.types.'.$export->report_type) }} · {{ strtoupper($export->format) }} · {{ \App\Support\Format\Ui::date($export->created_at, 'Y/m/d H:i') }}</span><span>@if($export->status === 'ready')<a class="text-primary font-bold" href="{{ route('statistics.exports.download', $export) }}">{{ __('Download') }}</a>@else{{ __('messages.statistics.export_status.'.$export->status) }}@endif</span></div>@empty<p class="text-gray-500">{{ __('No exports.') }}</p>@endforelse</div></section>
</div>
