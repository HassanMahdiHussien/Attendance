<div class="grid gap-5 {{ $email ? 'lg:grid-cols-2' : '' }}">
    <div class="min-w-0 max-w-3xl">
        <x-ui.page-header :title="$editingIncident ? __('Edit shared facts') : __('Open a new case')" />
        <x-ui.card class="space-y-5">
            @if($reportId)<x-ui.alert tone="indigo">{{ __('Report details are ready for review. Complete the fields and save the draft.') }}</x-ui.alert>@endif
            @if($sourceReport?->free_employee_names)<x-ui.alert tone="amber">{{ __('Names not in the employee list') }}: {{ implode(' · ', $sourceReport->free_employee_names) }}. {{ __('Add employees to the list before opening a case') }}</x-ui.alert>@endif
            @if(!$reportId && !$emailId && !$editingIncident)
                <div class="rounded-xl border border-dashed border-line p-4 text-center">
                    <p class="font-semibold">{{ __('Attach an email or document') }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ __('Email, PDF, Word or images') }}</p>
                    <input id="case-files" type="file" wire:model="caseFiles" multiple accept=".eml,.msg,.pdf,.docx,.jpg,.jpeg,.png,.webp" class="peer sr-only">
                    <label for="case-files" class="ui-button ui-button-secondary mt-3 inline-flex cursor-pointer peer-focus-visible:outline-2">{{ __('Choose files') }}</label>
                </div>
                @foreach($caseIntakeFiles as $file)
                    <div class="flex min-w-0 items-center gap-3 rounded-lg border border-line px-3 py-2 text-sm"><span dir="auto" class="min-w-0 flex-1 truncate">{{ $file->original_name }}</span><bdi dir="ltr" class="text-gray-500">{{ number_format($file->size_bytes / 1024) }} KB</bdi></div>
                @endforeach
                @error('caseFiles')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                @error('caseFiles.*')<p role="alert" class="text-sm text-red">{{ $message }}</p>@enderror
                @if($suggestedNames !== [])<p class="text-sm text-gray-600">{{ __('Names mentioned in the files. Match each person in the employee picker:') }} <span dir="auto">{{ implode(' · ', $suggestedNames) }}</span></p>@endif
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Branch')" for="branch"><x-ui.select id="branch" wire:model.live="branchId" :disabled="$incidentId !== null"><option value="">{{ __('Choose a branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</x-ui.select>@error('branchId')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <div><x-ui.people-picker kind="employees" :search="$peopleSearch['employees'] ?? ''" :label="__('Employee')" :items="$people['employees']" :results="$peopleResults['employees']" :free="false" :disabled="$incidentId !== null" />@error('employeeId')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</div>
                <x-ui.field :label="__('Location')" for="location"><x-ui.select id="location" wire:model="locationId"><option value="">{{ __('Enter the place below') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</x-ui.select></x-ui.field>
                <x-ui.field :label="__('Place if not listed')" for="place"><x-ui.input id="place" wire:model="place" />@error('place')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <x-ui.field :label="__('Incident date and time')" for="violation-time"><x-ui.input id="violation-time" type="datetime-local" wire:model="violationTime" />@error('violationTime')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <x-ui.field :label="__('Violation type')" for="violation-type-id"><x-ui.select id="violation-type-id" wire:model="violationTypeId"><option value="">{{ __('Other — describe below') }}</option>@foreach($violationTypes as $type)<option value="{{ $type->id }}">{{ $type->name() }}</option>@endforeach</x-ui.select><x-ui.input id="violation-type" wire:model="violationType" :placeholder="__('Violation if not listed')" class="mt-2" />@error('violationType')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <x-ui.people-picker kind="responsible" :search="$peopleSearch['responsible'] ?? ''" :label="__('Alleged responsible person')" :items="$people['responsible']" :results="$peopleResults['responsible']" />
                <x-ui.field :label="__('Person responsible for follow-up')" for="assigned-hr"><x-ui.select id="assigned-hr" wire:model="assignedHrUserId"><option value="">{{ __('Me') }}</option>@foreach($hrUsers as $hr)<option value="{{ $hr->id }}">{{ $hr->name }}</option>@endforeach</x-ui.select></x-ui.field>
                <x-ui.people-picker kind="witnesses" :search="$peopleSearch['witnesses'] ?? ''" :label="__('Witnesses')" :items="$people['witnesses']" :results="$peopleResults['witnesses']" />
            </div>
            <x-ui.field :label="__('Incident description')" for="description"><x-ui.textarea id="description" wire:model="description" rows="5" dir="auto" /></x-ui.field>
            @include('livewire.partials.intake-check', ['intakeCheck' => $intakeCheck, 'existingIntakeQuestions' => $existingIntakeQuestions])
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="includePriorRecord" class="mt-1">{{ __('Include prior record in the defense letter') }}</label>
            <div class="flex flex-wrap gap-3"><x-ui.button variant="secondary" wire:click="save">{{ $editingIncident ? __('Save shared facts') : __('Save draft') }}</x-ui.button>@if(!$editingIncident)<x-ui.button wire:click="checkDuplicates">{{ __('Check for duplicates') }}</x-ui.button>@endif</div>
            @if($checkedDuplicates)
                <x-ui.alert tone="amber">
                    <strong>{{ __('Duplicate check result') }}</strong>
                    @forelse($duplicates as $duplicate)<p class="mt-2">{{ $duplicate['employee'] }} · {{ __('Case number') }} <bdi class="font-mono">{{ \App\Support\Format\Ui::number($duplicate['number']) }}</bdi> <a class="ui-link" href="/cases/{{ $duplicate['id'] }}">{{ __('View case') }}</a></p>@empty<p class="mt-2">{{ __('No case for this employee within 48 hours.') }}</p>@endforelse
                    @if(count($duplicates) && !$incidentId)<x-ui.field :label="__('Link the new case as a duplicate report')" for="duplicate-case" class="mt-3"><x-ui.select id="duplicate-case" wire:model="duplicateCaseId"><option value="">{{ __('Independent follow-up') }}</option>@foreach($duplicates as $duplicate)<option value="{{ $duplicate['id'] }}">{{ __('Case') }} {{ \App\Support\Format\Ui::number($duplicate['number']) }}</option>@endforeach</x-ui.select></x-ui.field>@endif
                    <p class="mt-2 text-xs">{{ __('You can open a separate case after reviewing.') }}</p>
                </x-ui.alert>
                <x-ui.button wire:click="open">{{ __('Continue and open case') }}</x-ui.button>
            @endif
        </x-ui.card>
    </div>
    @if($email)
        <x-ui.card class="self-start" aria-label="{{ __('Forwarded email') }}">
            <x-ui.badge tone="indigo">{{ __('Forwarded email') }}</x-ui.badge>
            <h2 dir="auto" class="mt-4 font-bold">{{ $email->subject }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ $email->sender?->name }} · {{ $email->sender?->email }} · {{ \App\Support\Format\Ui::date($email->received_at, 'Y-m-d H:i') }}</p>
            <p dir="auto" class="my-4 whitespace-pre-line text-sm text-gray-700 [unicode-bidi:plaintext]">{{ $email->body_text }}</p>
            @php($emailItems = $email->attachments->values()->map(fn ($row) => \App\Support\Files\FileItem::forAttachment($row))->all())
            @foreach($email->attachments as $attachment)<p class="text-sm"><a class="ui-link text-sm" href="/attachments/{{ $attachment->id }}">{{ $attachment->original_name }}</a> <x-ui.file-action :item="$emailItems[$loop->index]" :items="$emailItems" :index="$loop->index" :show-download="false" /></p>@endforeach
        </x-ui.card>
    @endif
</div>
