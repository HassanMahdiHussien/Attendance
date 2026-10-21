<div class="space-y-5">
    @php
        $reportedPlace = in_array(trim((string) $report->place_freetext), [__('I do not know'),
            trans('report_assistant.chip.unknown', [], 'ar'), trans('report_assistant.chip.unknown', [], 'en')], true)
            ? __('Unknown') : ($report->place_freetext ?: __('Unknown'));
        $assistantSummary = $report->assistantSession && filled($report->assistantSession->facts['summary'] ?? null)
            && $report->summary === $report->assistantSession->facts['summary'];
    @endphp
    <x-ui.page-header :title="__('Open case from report :number', ['number' => 'R-'.str_pad((string) $report->report_number, 4, '0', STR_PAD_LEFT)])" />
    @if($draftNumbers->isNotEmpty())<p class="font-mono text-sm">{{ __('Case number') }}: @foreach($draftNumbers as $draftNumber)<bdi>{{ \App\Support\Format\Ui::number(str_pad((string) $draftNumber->case_number, 4, '0', STR_PAD_LEFT)) }}</bdi>{{ $loop->last ? '' : ' · ' }}@endforeach</p>@endif
    <p class="text-sm text-gray-600">{{ __('Review the report, match each named person, then open the case.') }}</p>
    <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <main class="min-w-0 space-y-5">
            <section class="ui-card overflow-hidden">
                <div class="border-b border-line p-5">
                    <div class="grid gap-2 rounded-xl bg-primary-light p-4">
                        <span class="justify-self-start rounded bg-white px-2 py-0.5 text-xs font-medium text-primary">{{ $assistantSummary ? __('Written by the assistant and approved by the reporter') : __('According to the reporter') }}</span>
                        <p dir="auto" class="max-w-[70ch] whitespace-pre-line text-base leading-loose">{{ $assistantSummary ? $report->summary : $report->description }}</p>
                    </div>
                </div>
                <fieldset @if($draftId) disabled @endif>
                <div class="border-b border-line p-5">
                    <h2 class="text-lg font-semibold">{{ __('People involved') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Match each name to an employee. One linked case opens per employee.') }}</p>
                    <div class="mt-3 divide-y divide-line">
                        @foreach($people as $index => $person)
                            <div wire:key="report-person-{{ $index }}" class="grid gap-2 py-3 sm:grid-cols-[11rem_minmax(0,1fr)] sm:gap-4">
                                <div><bdi class="font-medium">{{ $person['name'] }}</bdi><p class="text-xs text-gray-600">{{ __('As named by the reporter') }}</p></div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @foreach($person['candidates'] as $candidate)
                                        <button type="button" wire:click="selectPerson({{ $index }}, {{ $candidate['id'] }})" class="rounded-lg border px-3 py-1.5 text-start text-sm {{ (int) $person['selected_id'] === (int) $candidate['id'] ? 'border-green bg-green-light' : 'border-line' }}"><bdi>{{ $candidate['name'] }}</bdi><span class="block text-xs text-gray-600"><bdi>{{ $candidate['code'] }}</bdi></span></button>
                                    @endforeach
                                    @if($person['selected_id'] && !collect($person['candidates'])->contains('id', $person['selected_id']))<span class="rounded border border-line bg-bg px-3 py-1.5 text-sm">{{ __('Employee selected') }}</span>@endif
                                    @if($person['linked_id'])<span class="text-sm text-green">{{ __('Linked employee') }}</span>@elseif(!$person['selected_id'] && $person['candidates'] === [])<span class="text-sm text-amber-700">{{ __('No employee match found') }}</span>@endif
                                    @if(!$person['linked_id'])<button type="button" class="ui-link text-sm" wire:click="searchPerson({{ $index }})">{{ __('Search by name or employee number') }}</button><button type="button" class="ui-link text-sm" wire:click="startAddingPerson({{ $index }})">{{ __('Not in the employee list — add') }}</button>@endif
                                    @if($searchingRow === $index)
                                        <div class="w-full"><x-ui.people-picker kind="employees" :label="__('Employee')" :items="[]" :results="$searchResults" :search="$peopleSearch['employees']" :free="false" /></div>
                                    @endif
                                    @if($addingRow === $index)
                                        <div class="grid w-full gap-3 rounded-lg border border-line bg-bg p-4 sm:grid-cols-2">
                                            <x-ui.field :label="__('Name')" for="quick-name"><x-ui.input id="quick-name" wire:model="newEmployeeName" dir="auto" />@error('newEmployeeName')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                                            <x-ui.field :label="__('Branch')" for="quick-branch"><x-ui.input id="quick-branch" :value="$report->branch?->name" disabled /></x-ui.field>
                                            <x-ui.field :label="__('Position')" for="quick-position"><x-ui.input id="quick-position" wire:model="newEmployeePosition" />@error('newEmployeePosition')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                                            <x-ui.field :label="__('Department')" for="quick-department"><x-ui.input id="quick-department" wire:model="newEmployeeDepartment" />@error('newEmployeeDepartment')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                                            <x-ui.field :label="__('Hire date')" for="quick-hire"><x-ui.input id="quick-hire" type="date" wire:model="newEmployeeHireDate" />@error('newEmployeeHireDate')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                                            <div class="self-end"><x-ui.button type="button" variant="secondary" wire:click="addNewPerson">{{ __('Add employee') }}</x-ui.button></div>
                                        </div>
                                    @endif
                                    @error('people.'.$index)<p role="alert" class="w-full text-sm text-red">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        @endforeach
                        @error('people')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="space-y-4 p-5">
                    <h2 class="text-lg font-semibold">{{ __('Case details') }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field :label="__('Location')" for="report-location"><x-ui.select id="report-location" wire:model="locationId"><option value="">{{ __('Enter the place below') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</x-ui.select></x-ui.field>
                        <x-ui.field :label="__('Place if not listed')" for="report-place"><x-ui.input id="report-place" wire:model="place" dir="auto" /><p class="text-xs text-gray-600">{{ __('As reported: :value', ['value' => $reportedPlace]) }}</p>@error('place')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                        <x-ui.field :label="__('Incident date')" for="report-date"><x-ui.input id="report-date" type="date" wire:model="incidentDate" /><p class="text-xs text-gray-600">{{ __('As reported: :value', ['value' => $report->occurred_at_text ?: ($report->occurred_at ? \App\Support\Format\Ui::date($report->occurred_at).' · '.\App\Support\Format\Ui::date($report->occurred_at, 'H:i') : __('Unknown'))]) }}</p>@error('incidentDate')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                        <x-ui.field :label="__('Incident time, if known')" for="report-time"><x-ui.input id="report-time" type="time" wire:model="incidentTime" />@error('incidentTime')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                        <x-ui.field :label="__('Violation type')" for="report-type"><x-ui.select id="report-type" wire:model="violationTypeId"><option value="">{{ __('Other — describe below') }}</option>@foreach($violationTypes as $type)<option value="{{ $type->id }}">{{ $type->name() }}</option>@endforeach</x-ui.select><x-ui.input class="mt-2" wire:model="violationType" dir="auto" :placeholder="__('Violation if not listed')" /><p class="text-xs text-gray-600">{{ __('As reported: :value', ['value' => $report->violation_type]) }}</p>@error('violationType')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                        <x-ui.field :label="__('Person responsible for follow-up')" for="report-hr"><x-ui.select id="report-hr" wire:model="assignedHrUserId">@foreach($hrUsers as $hr)<option value="{{ $hr->id }}">{{ $hr->name }}</option>@endforeach</x-ui.select>@error('assignedHrUserId')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                    </div>
                    <x-ui.field :label="__('Incident description')" for="report-description"><x-ui.textarea id="report-description" wire:model="description" rows="5" dir="auto" />@if(trim($description) !== trim((string) $report->description))<p class="text-xs text-gray-600">{{ __('As reported: :value', ['value' => $report->description]) }}</p>@endif@error('description')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror</x-ui.field>
                    <p class="text-sm"><span class="font-medium">{{ __('Witnesses') }}:</span> @forelse($report->witnesses as $witness)<bdi>{{ $witness->name }}</bdi>{{ $loop->last ? '' : ' · ' }}@empty{{ $report->witnesses_none ? __('No witnesses') : ($report->witnesses_unknown ? __('Not known if anyone saw it') : __('No witnesses listed')) }}@endforelse</p>
                    {{-- Optional and usually empty, so it stays folded unless the report or HR named someone. --}}
                    @php $reportedResponsible = $report->allegedResponsibleEmployee?->name ?? $report->alleged_responsible_person_freetext; @endphp
                    <details class="rounded-lg border border-line px-4 py-3" @if($reportedResponsible || $responsibleName !== '') open @endif>
                    <summary class="cursor-pointer text-sm font-medium text-primary">{{ __('Alleged responsible person (optional)') }}</summary>
                    <x-ui.field :label="__('Alleged responsible person')" for="report-responsible" class="mt-3"><x-ui.input id="report-responsible" wire:model="responsibleName" dir="auto" />@if($reportedResponsible)<p class="text-xs text-gray-600">{{ __('As reported: :value', ['value' => $reportedResponsible]) }}</p>@endif<input type="search" wire:model.live.debounce.300ms="responsibleSearch" class="ui-control mt-2 w-full" dir="auto" placeholder="{{ __('Search by name or employee number') }}">@foreach($responsibleResults as $result)<button type="button" class="block w-full py-1 text-start text-sm ui-link" wire:click="selectResponsible({{ $result['id'] }})"><bdi>{{ $result['name'] }}</bdi> · <bdi>{{ $result['code'] }}</bdi></button>@endforeach</x-ui.field>
                    </details>
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="includePriorRecord" class="mt-1">{{ __('Include prior record in the defense letter') }}</label>
                </div>
                </fieldset>
                @if($findings !== [])
                    <div class="space-y-3 border-t border-line bg-signal-light p-5" role="status">
                        <h3 class="font-semibold">{{ __('Review before opening') }}</h3>
                        @foreach($findings['duplicates'] as $duplicate)<p class="text-sm">{{ __('Possible duplicate') }}: <bdi>{{ $duplicate['employee'] }}</bdi> · {{ __('Case number') }} <bdi>{{ \App\Support\Format\Ui::number($duplicate['number']) }}</bdi> <a class="ui-link" href="/cases/{{ $duplicate['id'] }}">{{ __('View case') }}</a></p>@endforeach
                        @foreach($findings['conflicts'] as $conflict)<p class="text-sm">{{ __('Possible conflict of interest') }}: <bdi>{{ $conflict['name'] }}</bdi> · <bdi>{{ $conflict['employee'] }}</bdi></p>@endforeach
                        <div class="flex flex-wrap gap-2">@foreach($findings['duplicates'] as $duplicate)@if(count($people) === 1)<x-ui.button variant="secondary" wire:click="confirmOpen({{ $duplicate['id'] }})">{{ __('Link as a duplicate of case :number', ['number' => $duplicate['number']]) }}</x-ui.button>@else<button type="button" wire:click="chooseDuplicate({{ $duplicate['member_id'] }}, {{ $duplicate['id'] }})" class="rounded-lg border border-line px-3 py-2 text-sm {{ ($duplicateLinks[$duplicate['member_id']] ?? null) === $duplicate['id'] ? 'bg-green-light text-green' : '' }}">{{ __('Link as a duplicate of case :number', ['number' => $duplicate['number']]) }}</button>@endif @endforeach<x-ui.button wire:click="confirmOpen">{{ count($duplicateLinks) ? __('Open cases with selected links') : __('Open as a separate case') }}</x-ui.button></div>
                    </div>
                @else
                    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-line p-5"><p class="max-w-[46ch] text-sm text-gray-600">{{ __('Opening checks duplicates and conflicts automatically.') }}</p><div class="flex gap-2">@if(!$draftId)<x-ui.button variant="secondary" wire:click="save">{{ __('Save draft') }}</x-ui.button>@else<a class="ui-link self-center text-sm" href="/cases/new?draft={{ $draftId }}">{{ __('Edit draft') }}</a>@endif<x-ui.button wire:click="open">{{ __('Open the case') }}</x-ui.button></div></footer>
                @endif
            </section>
        </main>
        <aside class="space-y-4">
            <x-ui.card><h2 class="font-semibold">{{ __('Report') }}</h2><dl class="mt-3 space-y-2 text-sm"><div><dt class="text-gray-600">{{ __('Reporter') }}</dt><dd><bdi>{{ $report->reporter?->name }}</bdi></dd></div><div><dt class="text-gray-600">{{ __('Sent') }}</dt><dd><bdi>{{ \App\Support\Format\Ui::date($report->created_at, 'Y-m-d H:i') }}</bdi></dd></div><div><dt class="text-gray-600">{{ __('Branch') }}</dt><dd><bdi>{{ $report->branch?->name }}</bdi></dd></div></dl></x-ui.card>
            @if($report->assistantSession)<x-ui.card><h2 class="font-semibold">{{ __('Conversation') }}</h2><div lang="{{ $report->assistantSession->language }}" class="mt-3 max-h-72 space-y-2 overflow-auto">@foreach($report->assistantSession->transcript ?? [] as $message)<p dir="auto" class="rounded-lg p-2 text-sm {{ ($message['role'] ?? '') === 'assistant' ? 'bg-bg' : 'bg-primary-light' }}">{{ $message['text'] ?? '' }}</p>@endforeach</div></x-ui.card>@endif
            @if($report->attachments->isNotEmpty())<x-ui.card><h2 class="font-semibold">{{ __('Attachments') }}</h2>@foreach($report->attachments as $attachment)<p class="mt-2 text-sm"><a class="ui-link" href="/attachments/{{ $attachment->id }}"><bdi>{{ $attachment->original_name }}</bdi></a></p>@endforeach</x-ui.card>@endif
            @if($report->intakeQuestions->isNotEmpty())<x-ui.card><h2 class="font-semibold">{{ __('Intake answers') }}</h2>@foreach($report->intakeQuestions as $question)<p dir="auto" class="mt-2 text-sm">{{ $question->question }} · {{ $question->answer }}</p>@endforeach</x-ui.card>@endif
        </aside>
    </div>
</div>
