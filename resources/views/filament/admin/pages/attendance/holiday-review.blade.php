<x-filament-panels::page>
    <div class="grid gap-5 lg:grid-cols-2">
        <x-ui.card>
            <h2 class="font-semibold">{{ __('messages.holiday_ui.source') }}</h2>
            @if ($source = $this->source())
                <a class="ui-link" href="{{ route('attendance.holidays.original', ['company' => $this->company()->id, 'source' => $source->id]) }}"><bdi dir="ltr">{{ $source->file_name }}</bdi> · {{ __('messages.holiday_ui.download_original') }}</a>
                <p>{{ __('messages.holiday_ui.ai_statuses.'.$source->ai_status) }}</p>
                @if ($source->error_code)<p class="text-amber-800">{{ __('messages.holidays.ai_errors.'.$source->error_code) }}</p>@endif
                @if ($parts = $this->parts())
                    <div class="flex flex-wrap gap-2">@foreach ($parts as $index => $part)<button type="button" wire:click="selectPart({{ $index }})" class="rounded border p-2 {{ $index === $partIndex ? 'border-primary font-semibold' : 'border-gray-200' }}">{{ $part['name'] }} @if (isset($part['page'])) <bdi dir="ltr">{{ $part['page'] }}</bdi> @endif</button>@endforeach</div>
                    <div class="flex gap-2"><button type="button" wire:click="previousPart" @disabled($partIndex === 0) class="ui-link">{{ __('messages.holiday_ui.previous') }}</button><button type="button" wire:click="nextPart" @disabled($partIndex >= count($parts) - 1) class="ui-link">{{ __('messages.holiday_ui.next') }}</button></div>
                    @if ($part = $this->part())
                        @if (in_array($part['kind'], ['image', 'page'], true))
                            <img class="max-h-[70vh] w-full object-contain" src="{{ route('attendance.holidays.part', ['company' => $this->company()->id, 'source' => $source->id, 'part' => $partIndex]) }}" alt="{{ $part['name'] }}">
                        @else
                            <iframe class="h-[70vh] w-full rounded border" title="{{ $part['name'] }}" src="{{ route('attendance.holidays.part', ['company' => $this->company()->id, 'source' => $source->id, 'part' => $partIndex]) }}"></iframe>
                        @endif
                    @endif
                @endif
                @if ($proposal = $source->proposal)
                    <div class="mt-4 space-y-2 rounded-xl bg-amber-50 p-3">
                        <p>{{ __('messages.holiday_ui.ai_confidence') }}: {{ __('messages.holiday_ui.confidence.'.$proposal['confidence']) }}</p>
                        @foreach (['scope_quotes', 'exception_quotes', 'notes'] as $kind)
                            <h3 class="font-semibold">{{ __('messages.holiday_ui.'.$kind) }}</h3>
                            @foreach ($proposal[$kind] ?? [] as $line)<p>{{ $line }}</p>@endforeach
                        @endforeach
                    </div>
                @endif
            @else <p>{{ __('messages.holiday_ui.manual') }}</p> @endif
        </x-ui.card>
        <div class="space-y-5">
            <x-ui.card>
                <h2 class="font-semibold">{{ __('messages.holiday_ui.details') }}</h2>
                <div class="space-y-3">
                    <label class="block">{{ __('messages.holiday_ui.name_en') }} <input wire:model="nameEn" class="w-full rounded-xl border border-gray-200 p-2" dir="ltr"></label>
                    <label class="block">{{ __('messages.holiday_ui.name_ar') }} <input wire:model="nameAr" class="w-full rounded-xl border border-gray-200 p-2" dir="rtl"></label>
                    @foreach ($ranges as $index => $range)
                        <div class="flex flex-wrap gap-2" wire:key="holiday-range-{{ $index }}">
                            <label>{{ __('messages.holiday_ui.from') }} <input type="date" wire:model="ranges.{{ $index }}.from" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                            <label>{{ __('messages.holiday_ui.to') }} <input type="date" wire:model="ranges.{{ $index }}.to" dir="ltr" class="rounded-xl border border-gray-200 p-2"></label>
                            <button type="button" class="ui-link" wire:click="removeRange({{ $index }})">{{ __('messages.holiday_ui.remove_range') }}</button>
                            @error('ranges.'.$index.'.from') <p class="text-red-600">{{ $message }}</p> @enderror
                            @error('ranges.'.$index.'.to') <p class="text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <button type="button" class="ui-link" wire:click="addRange">{{ __('messages.holiday_ui.add_range') }}</button>
                    <label class="block">{{ __('messages.holiday_ui.legal_entities') }} <select wire:model="legalEntities" multiple class="w-full rounded-xl border border-gray-200 p-2">@foreach ($this->entities() as $entity)<option value="{{ $entity }}">{{ $entity }}</option>@endforeach</select></label>
                    <label class="block">{{ __('messages.holiday_ui.branches') }} <select wire:model="branchIds" multiple class="w-full rounded-xl border border-gray-200 p-2">@foreach ($this->branches() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
                    <label class="block">{{ __('messages.holiday_ui.work_patterns') }} <select wire:model="workPatterns" multiple class="w-full rounded-xl border border-gray-200 p-2">@foreach (['office', 'shift', 'security'] as $pattern)<option value="{{ $pattern }}">{{ __('messages.holiday_ui.patterns.'.$pattern) }}</option>@endforeach</select></label>
                    <p class="text-xs text-gray-500">{{ __('messages.holiday_ui.scope_hint') }}</p>
                    <label class="block">{{ __('messages.holiday_ui.exceptions_note') }} <textarea wire:model="exceptionsNote" class="w-full rounded-xl border border-gray-200 p-2"></textarea></label>
                    <label class="block">{{ __('messages.holiday_ui.reason') }} <textarea wire:model="reason" class="w-full rounded-xl border border-gray-200 p-2"></textarea></label>
                    @foreach (['nameEn', 'nameAr', 'ranges', 'branchIds', 'reason', 'holiday'] as $field) @error($field) <p class="text-red-600">{{ $message }}</p> @enderror @endforeach
                    @error('branch_ids') <p class="text-red-600">{{ $message }}</p> @enderror
                    @error('scope') <p class="text-red-600">{{ $message }}</p> @enderror
                    @if (! $this->holiday())
                        <x-filament::button wire:click="save">{{ __('messages.holiday_ui.create_draft') }}</x-filament::button>
                    @elseif ($this->holiday()->status === 'draft')
                        <x-filament::button color="warning" wire:click="approve" wire:confirm="{{ __('messages.holiday_ui.confirm_approve') }}">{{ __('messages.holiday_ui.approve') }}</x-filament::button>
                        <button type="button" class="ui-link" wire:click="reject" wire:confirm="{{ __('messages.holiday_ui.confirm_reject') }}">{{ __('messages.holiday_ui.reject') }}</button>
                    @elseif ($this->holiday()->status === 'approved')
                        <x-filament::button color="warning" wire:click="save" wire:confirm="{{ __('messages.holiday_ui.confirm_save') }}">{{ __('messages.holiday_ui.save_version') }}</x-filament::button>
                        <button type="button" class="ui-link" wire:click="withdraw" wire:confirm="{{ __('messages.holiday_ui.confirm_withdraw') }}">{{ __('messages.holiday_ui.withdraw') }}</button>
                    @endif
                </div>
            </x-ui.card>
            @if ($this->holiday())
                <x-ui.card>
                    <h2 class="font-semibold">{{ __('messages.holiday_ui.history') }}</h2>
                    <p>{{ __('messages.holiday_ui.affected_days') }}: <bdi dir="ltr">{{ $this->affectedDays() }}</bdi></p>
                    @foreach ($this->holiday()->versions->sortByDesc('version') as $version)
                        <div class="border-t py-3" wire:key="holiday-version-{{ $version->id }}">
                            <p><bdi dir="ltr">#{{ $version->version }} · {{ $version->created_at->format('Y-m-d H:i') }}</bdi> — {{ __('messages.holiday_ui.statuses.'.$version->status) }} · {{ $version->decider?->name }}</p>
                            <p>{{ $version->reason }}</p>
                            @foreach ($this->differences()[$version->id] ?? [] as $field => $change)
                                <p>{{ __('messages.holiday_ui.fields.'.$field) }}: <bdi dir="auto">{{ $this->displayValue($change['before']) }}</bdi> → <bdi dir="auto">{{ $this->displayValue($change['after']) }}</bdi></p>
                            @endforeach
                        </div>
                    @endforeach
                </x-ui.card>
            @endif
        </div>
    </div>
</x-filament-panels::page>
