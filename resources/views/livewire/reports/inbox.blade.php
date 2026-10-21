<div class="space-y-5">
    <x-ui.page-header :title="__('Reports inbox')" />
    <div class="space-y-4">
        @forelse($items as $item)
            @if($item['type'] === 'email')
                @php($email = $item['model'])
                <x-ui.card wire:key="email-{{ $email->id }}" class="space-y-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div><x-ui.badge tone="indigo">{{ __('Forwarded email') }}</x-ui.badge><h2 dir="auto" class="mt-2 font-bold text-ink">{{ $email->subject }}</h2></div>
                        <span class="text-sm text-gray-500">{{ $email->sender?->name }} · {{ $email->sender?->email }} · {{ \App\Support\Format\Ui::date($email->received_at, 'Y-m-d H:i') }}</span>
                    </div>
                    <p dir="auto" class="whitespace-pre-line text-sm text-gray-600 [unicode-bidi:plaintext]">{{ $email->body_text }}</p>
                    @if($email->intakeQuestions->isNotEmpty())
                        <div class="space-y-1"><h3 class="font-semibold">{{ __('messages.intake.open_points') }}</h3>
                            @foreach($email->intakeQuestions as $question)<p dir="auto" class="text-sm"><strong>{{ $question->question }}</strong> — {{ $question->answer ?? __('messages.intake.open') }}</p>@endforeach
                        </div>
                    @endif
                    @if($email->body_truncated)<p class="text-xs text-gray-500">{{ __('The email body was shortened.') }}</p>@endif
                    @php($emailItems = $email->attachments->values()->map(fn ($row) => \App\Support\Files\FileItem::forAttachment($row))->all())
                    <div class="flex flex-wrap gap-3">@foreach($email->attachments as $attachment)<span class="text-sm"><a class="ui-link text-sm" href="/attachments/{{ $attachment->id }}">{{ $attachment->original_name }}</a> <x-ui.file-action :item="$emailItems[$loop->index]" :items="$emailItems" :index="$loop->index" :show-download="false" /></span>@endforeach</div>
                    @foreach($email->excluded_attachments ?? [] as $name)<p class="text-xs text-gray-500">{{ __('Excluded attachment: :name', ['name' => $name]) }}</p>@endforeach
                    <div class="flex flex-wrap items-end gap-3">
                        <x-ui.button wire:click="convertEmail({{ $email->id }})">{{ __('Create case') }}</x-ui.button>
                        <x-ui.field :label="__('Link to an existing case')" for="email-case-{{ $email->id }}" class="min-w-44">
                            <select id="email-case-{{ $email->id }}" wire:model="emailCaseIds.{{ $email->id }}" class="ui-control"><option value="">{{ __('New case') }}</option>@foreach(\App\Support\Access\CaseVisibility::for(auth()->user())->latest()->get() as $case)<option value="{{ $case->id }}">{{ \App\Support\Format\Ui::number($case->case_number) }}</option>@endforeach</select>
                        </x-ui.field>
                        <x-ui.button variant="secondary" wire:click="linkEmail({{ $email->id }})">{{ __('Link to case') }}</x-ui.button>
                        <x-ui.field :label="__('Dismissal reason')" for="email-reason-{{ $email->id }}"><x-ui.input id="email-reason-{{ $email->id }}" wire:model="emailReasons.{{ $email->id }}" /></x-ui.field>
                        <x-ui.button variant="secondary" wire:click="dismissEmail({{ $email->id }})">{{ __('Dismiss') }}</x-ui.button>
                    </div>
                    @error('emailReasons.'.$email->id)<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                </x-ui.card>
            @else
                @php($report = $item['model'])
                {{-- The inbox only triages; the full report, conversation and people matching live on the open-case screen. --}}
                <x-ui.card wire:key="report-{{ $report->id }}" class="space-y-3">
                    @include('livewire.reports.partials.report-row')
                    <p class="text-sm text-gray-600">{{ __('Reporter') }}: <bdi>{{ $report->reporter?->name }}</bdi> · {{ __('Sent') }} <bdi class="font-mono text-xs">{{ \App\Support\Format\Ui::date($report->created_at) }}</bdi>@if($report->attachments->isNotEmpty()) · {{ __('Attachments') }}: {{ \App\Support\Format\Ui::number($report->attachments->count()) }}@endif</p>
                    @if($report->summary)<p class="line-clamp-3 max-w-[75ch] rounded-lg bg-primary-light p-3 text-sm leading-relaxed"><bdi>{{ $report->summary }}</bdi></p>@endif
                    <div class="flex flex-wrap items-center gap-3">
                        {{-- A repeated list action, so secondary: amber stays for a screen's single main action. --}}
                        <x-ui.button variant="secondary" wire:click="convert({{ $report->id }})">{{ __('Convert to case') }}</x-ui.button>
                    </div>
                    <details class="rounded-lg border border-line px-4 py-3" @if($errors->has('reasons.'.$report->id) || $errors->has('mapViolationTypeIds.'.$report->id)) open @endif>
                        <summary class="cursor-pointer text-sm font-medium text-primary">{{ __('More actions') }}</summary>
                        <div class="mt-3 space-y-4">
                            @if($report->employees->count() === 1 && ! $report->free_employee_names)
                                <div class="flex flex-wrap items-end gap-3">
                                    <x-ui.field :label="__('Link to a duplicate case')" for="report-case-{{ $report->id }}" class="min-w-44">
                                        <select id="report-case-{{ $report->id }}" wire:model="existingCaseIds.{{ $report->id }}" class="ui-control"><option value="">{{ __('New case') }}</option>@foreach($insights->duplicateCandidates($report)['cases'] as $case)@if(\App\Support\Access\CaseVisibility::for(auth()->user())->whereKey($case->id)->exists())<option value="{{ $case->id }}">{{ \App\Support\Format\Ui::number($case->case_number) }}</option>@endif@endforeach</select>
                                    </x-ui.field>
                                    <x-ui.button variant="secondary" wire:click="convert({{ $report->id }})">{{ __('Link to case') }}</x-ui.button>
                                </div>
                            @endif
                            @if($report->violation_type_id === null && $violationTypes->isNotEmpty())
                                <div class="flex flex-wrap items-end gap-3"><x-ui.field :label="__('Map to a violation type')" for="report-type-{{ $report->id }}"><x-ui.select id="report-type-{{ $report->id }}" wire:model="mapViolationTypeIds.{{ $report->id }}"><option value="">{{ __('Choose a violation type') }}</option>@foreach($violationTypes as $type)<option value="{{ $type->id }}">{{ $type->name() }}</option>@endforeach</x-ui.select></x-ui.field><x-ui.button variant="secondary" wire:click="mapViolationType({{ $report->id }})">{{ __('Map violation type') }}</x-ui.button></div>
                                @error('mapViolationTypeIds.'.$report->id)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                            @endif
                            <div class="flex flex-wrap items-end gap-3">
                                <x-ui.field :label="__('Dismissal reason')" for="report-reason-{{ $report->id }}"><x-ui.input id="report-reason-{{ $report->id }}" wire:model="reasons.{{ $report->id }}" /></x-ui.field>
                                <x-ui.button variant="secondary" wire:click="dismiss({{ $report->id }})">{{ __('Dismiss') }}</x-ui.button>
                            </div>
                            @error('reasons.'.$report->id)<p class="text-sm text-red-700" role="alert">{{ $message }}</p>@enderror
                        </div>
                    </details>
                </x-ui.card>
            @endif
        @empty
            <x-ui.card><x-ui.empty :title="__('No new reports.')" /></x-ui.card>
        @endforelse
    </div>
</div>
