<x-filament-panels::page>
    <div class="space-y-6">
        @if (auth()->user()->primary_role === \App\Enums\UserRole::CompanyAdmin)
            <form wire:submit="upload" class="rounded-xl border p-4 space-y-3 dark:border-gray-700">
                <h2 class="font-bold">{{ __('Upload internal regulation') }}</h2>
                <input type="file" wire:model="sourceFile" accept=".txt,.docx,.pdf" class="block w-full">
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                <input type="text" wire:model="regulationName" placeholder="{{ __('Regulation name') }}" class="block w-full rounded border p-2" required>
                @error('regulationName') <p class="text-red-600">{{ $message }}</p> @enderror
                <div class="flex gap-2">
                    <input type="text" wire:model="regulationNumber" placeholder="{{ __('Number') }}" class="rounded border p-2">
                    <input type="number" wire:model="regulationYear" placeholder="{{ __('Year') }}" class="rounded border p-2">
                </div>
                <x-filament::button type="submit">{{ __('Start import') }}</x-filament::button>
            </form>
        @endif

        <section class="rounded-xl border p-4 dark:border-gray-700">
            <h2 class="font-bold">{{ __('Internal regulations') }}</h2>
            @foreach ($this->laws() as $law)
                <button type="button" wire:click="selectLaw({{ $law->id }})" class="block w-full border-b p-3 text-start">
                    {{ $law->name }} —
                    @if ($state = $law->importRuns()->latest('id')->value('state'))
                        <x-filament::badge :color="\App\Support\Format\LegalLibraryLabels::statusColor($state)">{{ \App\Support\Format\LegalLibraryLabels::runState($state) }}</x-filament::badge>
                    @else
                        {{ __('Not started') }}
                    @endif
                    <span class="text-sm">{{ $this->lawSummary($law) }}</span>
                </button>
            @endforeach
        </section>

        @if ($selectedLawId)
            <section class="rounded-xl border p-4 dark:border-gray-700">
                <div class="flex justify-between">
                    <h2 class="font-bold">{{ __('Articles and clauses') }}</h2>
                    @if ($law = $this->laws()->firstWhere('id', $selectedLawId))
                        @can('review', $law)
                            @if ($this->hasPendingApprovals($law))
                                <x-filament::button wire:click="approveAll">{{ __('Approve all verified units') }}</x-filament::button>
                            @endif
                        @endcan
                    @endif
                </div>
                @foreach ($this->units() as $unit)
                    @php($approval = \App\Models\LegalUnitApproval::query()->where('legal_unit_id', $unit->id)->first())
                    <div class="border-b p-3">
                        <div class="flex flex-wrap gap-3">
                            <strong>{{ __('Article') }} {{ $unit->article_number }} {{ $unit->clause_path }}</strong>
                            <x-filament::badge :color="\App\Support\Format\LegalLibraryLabels::statusColor($unit->status->value)">{{ \App\Support\Format\LegalLibraryLabels::unitStatus($unit->status->value) }}</x-filament::badge>
                            @if ($approval)
                                <span>{{ __('Approved') }} — {{ \App\Models\User::find($approval->approved_by)?->name }}, {{ \App\Support\Format\LegalLibraryLabels::dateTime(\Carbon\Carbon::parse($approval->created_at)) }}</span>
                            @else
                                <span>{{ __('Pending approval') }}</span>
                            @endif
                            @if ($unit->source_page !== null && $unit->import_run_id !== null)
                                <a target="_blank" class="underline" href="{{ route('legal-library.pages.show', ['run' => $unit->import_run_id, 'page' => $unit->source_page]) }}">{{ __('Page image') }}</a>
                            @endif
                            @can('review', $unit->law)
                                @if (! $approval && in_array($unit->status->value, ['verified_auto', 'verified_human', 'verified_text'], true) && $unit->superseded_by_id === null)
                                    <x-filament::button size="sm" wire:click="approveUnit({{ $unit->id }})">{{ __('Approve') }}</x-filament::button>
                                @endif
                            @endcan
                        </div>
                        <p dir="rtl" class="mt-2 whitespace-pre-wrap text-xl" style="font-family:Amiri,serif">{{ $unit->official_text }}</p>
                    </div>
                @endforeach
            </section>
        @endif

        @if (auth()->user()->can('review_internal_regulation'))
            @include('filament.partials.legal-mismatch-review')
        @endif
    </div>
</x-filament-panels::page>
