<form wire:submit="submit" class="max-w-3xl space-y-5">
    <x-ui.page-header :title="__('Submit report')" />
    <x-ui.card class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Branch')" error="branchId" for="report-branch">
                <select id="report-branch" wire:model.live="branchId" class="ui-control"><option value="">{{ __('Select branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
                @error('branchId')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Employee concerned')" for="report-employee">
                <x-ui.input wire:model.live.debounce.300ms="employeeSearch" :placeholder="__('Search for an employee')" aria-label="{{ __('Search for an employee') }}" />
                <select id="report-employee" wire:model="employeeId" class="ui-control mt-2" aria-label="{{ __('Employee concerned') }}"><option value="">{{ __('Not specified') }}</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field :label="__('Location')" for="report-location">
                <select id="report-location" wire:model="locationId" class="ui-control"><option value="">{{ __('Enter the place below') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field :label="__('Place if not listed')" error="place" for="report-place">
                <x-ui.input id="report-place" wire:model="place" />@error('place')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Incident date and time')" error="occurredAt" for="report-occurred-at">
                <x-ui.input id="report-occurred-at" type="datetime-local" wire:model="occurredAt" />@error('occurredAt')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Violation type')" error="violationType" for="report-violation-type">
                <x-ui.input id="report-violation-type" wire:model="violationType" />@error('violationType')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Alleged responsible person')" for="report-responsible">
                <x-ui.input wire:model.live.debounce.300ms="responsibleSearch" :placeholder="__('Search for an employee')" aria-label="{{ __('Search for an employee') }}" />
                <select id="report-responsible" wire:model="responsibleEmployeeId" class="ui-control mt-2" aria-label="{{ __('Alleged responsible person') }}"><option value="">{{ __('Not listed') }}</option>@foreach($responsibleEmployees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field :label="__('Name if not listed')" for="report-responsible-name"><x-ui.input id="report-responsible-name" wire:model="responsibleName" /></x-ui.field>
        </div>
        <x-ui.field :label="__('Witnesses, separated by commas')" for="report-witnesses"><x-ui.input id="report-witnesses" wire:model="witnessNames" /></x-ui.field>
        <x-ui.field :label="__('Incident description')" error="description" for="report-description">
            <textarea id="report-description" wire:model="description" rows="5" class="ui-control"></textarea>@error('description')<span class="sr-only">{{ $message }}</span>@enderror
        </x-ui.field>
        <x-ui.field :label="__('Attachments (PDF or images, up to 10 MB per file)')" error="attachments.*" for="report-attachments">
            <input id="report-attachments" type="file" wire:model="attachments" multiple accept=".pdf,.jpg,.jpeg,.png" class="ui-control">@error('attachments.*')<span class="sr-only">{{ $message }}</span>@enderror
        </x-ui.field>
        <x-ui.button type="submit">{{ __('Send report') }}</x-ui.button>
    </x-ui.card>
</form>
