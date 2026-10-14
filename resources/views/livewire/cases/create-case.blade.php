<div class="grid gap-5 {{ $email ? 'lg:grid-cols-2' : '' }}">
    <div class="min-w-0 max-w-3xl">
        <x-ui.page-header :title="__('Open a new case')" />
        <x-ui.card class="space-y-5">
            @if($reportId)<x-ui.alert tone="indigo">{{ __('Report details are ready for review. Complete the fields and save the draft.') }}</x-ui.alert>@endif
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('Branch')" for="branch"><x-ui.select id="branch" wire:model.live="branchId"><option value="">{{ __('Choose a branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</x-ui.select>@error('branchId')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <x-ui.field :label="__('Employee')" for="employee"><x-ui.input wire:model.live.debounce.300ms="employeeSearch" aria-label="{{ __('Search for an employee') }}" placeholder="{{ __('Search for an employee') }}" class="mb-2" /><x-ui.select id="employee" wire:model="employeeId"><option value="">{{ __('Choose an employee') }}</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</x-ui.select>@error('employeeId')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <x-ui.field :label="__('Location')" for="location"><x-ui.select id="location" wire:model="locationId"><option value="">{{ __('Enter the place below') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</x-ui.select></x-ui.field>
                <x-ui.field :label="__('Place if not listed')" for="place"><x-ui.input id="place" wire:model="place" />@error('place')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <x-ui.field :label="__('Incident date and time')" for="violation-time"><x-ui.input id="violation-time" type="datetime-local" wire:model="violationTime" />@error('violationTime')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <x-ui.field :label="__('Violation type')" for="violation-type-id"><x-ui.select id="violation-type-id" wire:model="violationTypeId"><option value="">{{ __('Other — describe below') }}</option>@foreach($violationTypes as $type)<option value="{{ $type->id }}">{{ $type->name() }}</option>@endforeach</x-ui.select><x-ui.input id="violation-type" wire:model="violationType" :placeholder="__('Violation if not listed')" class="mt-2" />@error('violationType')<span class="text-sm text-red-700">{{ $message }}</span>@enderror</x-ui.field>
                <x-ui.field :label="__('Alleged responsible person')" for="responsible"><x-ui.input wire:model.live.debounce.300ms="responsibleSearch" aria-label="{{ __('Search for an employee') }}" placeholder="{{ __('Search for an employee') }}" class="mb-2" /><x-ui.select id="responsible" wire:model="responsibleEmployeeId"><option value="">{{ __('Not listed') }}</option>@foreach($responsibleEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</x-ui.select></x-ui.field>
                <x-ui.field :label="__('Name if not listed')" for="responsible-name"><x-ui.input id="responsible-name" wire:model="responsibleName" /></x-ui.field>
                <x-ui.field :label="__('Person responsible for follow-up')" for="assigned-hr"><x-ui.select id="assigned-hr" wire:model="assignedHrUserId"><option value="">{{ __('Me') }}</option>@foreach($hrUsers as $hr)<option value="{{ $hr->id }}">{{ $hr->name }}</option>@endforeach</x-ui.select></x-ui.field>
                <x-ui.field :label="__('Witnesses, separated by commas')" for="witnesses"><x-ui.input id="witnesses" wire:model="witnessNames" /></x-ui.field>
            </div>
            <x-ui.field :label="__('Incident description')" for="description"><x-ui.textarea id="description" wire:model="description" rows="5" dir="auto" /></x-ui.field>
            @include('livewire.partials.intake-check', ['intakeCheck' => $intakeCheck, 'existingIntakeQuestions' => $existingIntakeQuestions])
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="includePriorRecord" class="mt-1">{{ __('Include prior record in the defense letter') }}</label>
            <div class="flex flex-wrap gap-3"><x-ui.button variant="secondary" wire:click="save">{{ __('Save draft') }}</x-ui.button><x-ui.button wire:click="checkDuplicates">{{ __('Check for duplicates') }}</x-ui.button></div>
            @if($checkedDuplicates)
                <x-ui.alert tone="amber">
                    <strong>{{ __('Duplicate check result') }}</strong>
                    @forelse($duplicates as $duplicate)<p class="mt-2">{{ __('Case number') }} <bdi class="font-mono">{{ \App\Support\Format\Ui::number($duplicate['number']) }}</bdi> <a class="ui-link" href="/cases/{{ $duplicate['id'] }}">{{ __('View case') }}</a></p>@empty<p class="mt-2">{{ __('No case for this employee within 48 hours.') }}</p>@endforelse
                    @if(count($duplicates))<x-ui.field :label="__('Link the new case as a duplicate report')" for="duplicate-case" class="mt-3"><x-ui.select id="duplicate-case" wire:model="duplicateCaseId"><option value="">{{ __('Independent follow-up') }}</option>@foreach($duplicates as $duplicate)<option value="{{ $duplicate['id'] }}">{{ __('Case') }} {{ \App\Support\Format\Ui::number($duplicate['number']) }}</option>@endforeach</x-ui.select></x-ui.field>@endif
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
