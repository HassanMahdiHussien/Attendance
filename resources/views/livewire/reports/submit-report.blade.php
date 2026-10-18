<div class="max-w-3xl space-y-5">
    <x-ui.page-header :title="__('Submit report')" />
    @if($reviewing)
        <x-ui.card class="space-y-5" aria-label="{{ __('Review report before sending') }}">
            <h2 class="text-lg font-semibold">{{ __('Review report before sending') }}</h2>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="font-medium">{{ __('Employees named in report') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd dir="auto">{{ collect($people['employees'])->pluck('name')->implode(' · ') ?: __('Not specified') }}</dd></div>
                <div><dt class="font-medium">{{ __('Witnesses') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd dir="auto">{{ collect($people['witnesses'])->pluck('name')->implode(' · ') ?: __('Not specified') }}</dd></div>
                <div><dt class="font-medium">{{ __('Branch') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>{{ $branches->firstWhere('id', (int) $branchId)?->name }}</dd></div>
                <div><dt class="font-medium">{{ __('Location') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>{{ $locations->firstWhere('id', (int) $locationId)?->name ?? __('Not specified') }}</dd></div>
                <div><dt class="font-medium">{{ __('Place if not listed') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd dir="auto">{{ $place ?: __('Not specified') }}</dd></div>
                <div><dt class="font-medium">{{ __('Incident date and time') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>@if($occurredAt !== '')<bdi>{{ \App\Support\Format\Ui::date($occurredAt) }} · {{ \App\Support\Format\Ui::date($occurredAt, 'H:i') }}</bdi>@else{{ __('Not specified') }}@endif</dd></div>
                <div><dt class="font-medium">{{ __('Violation type') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>{{ $violationTypes->firstWhere('id', (int) $violationTypeId)?->name() ?? ($violationType ?: __('Not specified')) }} @if($violationTypeId !== '' && $violationType !== '') · {{ $violationType }} @endif</dd></div>
                <div class="sm:col-span-2"><dt class="font-medium">{{ __('Incident description') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd dir="auto" class="whitespace-pre-line">{{ $description }}</dd></div>
                @if(!empty($intakeCheck?->result['questions'] ?? []))<div class="sm:col-span-2"><dt class="font-medium">{{ __('messages.intake.open_points') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>@foreach(($intakeCheck?->result['questions'] ?? []) as $index => $question)<p dir="auto">{{ $question['question'] }} — {{ $intakeAnswers[$index] ?? __('messages.intake.open') }}</p>@endforeach</dd></div>@endif
                <div class="sm:col-span-2"><dt class="font-medium">{{ __('Attachments') }} <button type="button" wire:click="editReview" class="ui-link text-sm">{{ __('Edit') }}</button></dt><dd>{{ collect($attachments)->map(fn ($file) => $file->getClientOriginalName())->implode(' · ') ?: __('Not specified') }}</dd></div>
            </dl>
            <div class="flex flex-wrap gap-3"><x-ui.button type="button" variant="secondary" wire:click="editReview">{{ __('Edit report') }}</x-ui.button><x-ui.button type="button" wire:click="confirmSend">{{ __('Confirm and send') }}</x-ui.button></div>
        </x-ui.card>
    @else
    <form wire:submit="submit" class="space-y-5">
    @if($branches->isEmpty())
        <p role="alert" class="rounded-xl bg-amber-50 p-4 text-amber-900">{{ __('Your account is not assigned to any branch yet. Ask HR to add your branch before submitting a report.') }}</p>
    @endif
    <x-ui.card class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.field :label="__('Branch')" error="branchId" for="report-branch">
                <select id="report-branch" wire:model.live="branchId" class="ui-control"><option value="">{{ __('Select branch') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
                @error('branchId')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.people-picker kind="employees" :search="$peopleSearch['employees'] ?? ''" :label="__('Employee concerned')" :items="$people['employees']" :results="$peopleResults['employees']" />
            <x-ui.field :label="__('Location')" for="report-location">
                <select id="report-location" wire:model="locationId" class="ui-control"><option value="">{{ __('Enter the place below') }}</option>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select>
            </x-ui.field>
            <x-ui.field :label="__('Place if not listed')" error="place" for="report-place">
                <x-ui.input id="report-place" wire:model="place" />@error('place')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Incident date and time')" error="occurredAt" for="report-occurred-at">
                <x-ui.input id="report-occurred-at" type="datetime-local" wire:model="occurredAt" />@error('occurredAt')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
            <x-ui.field :label="__('Violation type')" error="violationType" for="report-violation-type-id">
                <x-ui.select id="report-violation-type-id" wire:model="violationTypeId"><option value="">{{ __('Other — describe below') }}</option>@foreach($violationTypes as $type)<option value="{{ $type->id }}">{{ $type->name() }}</option>@endforeach</x-ui.select>
                <x-ui.input id="report-violation-type" wire:model="violationType" :placeholder="__('Violation if not listed')" class="mt-2" />@error('violationType')<span class="sr-only">{{ $message }}</span>@enderror
            </x-ui.field>
        </div>
        <x-ui.people-picker kind="witnesses" :search="$peopleSearch['witnesses'] ?? ''" :label="__('Witnesses')" :items="$people['witnesses']" :results="$peopleResults['witnesses']" />
        <x-ui.field :label="__('Incident description')" error="description" for="report-description">
            <textarea id="report-description" wire:model="description" rows="5" class="ui-control"></textarea>@error('description')<span class="sr-only">{{ $message }}</span>@enderror
        </x-ui.field>
        @include('livewire.partials.intake-check', ['intakeCheck' => $intakeCheck])
        <x-ui.field :label="__('Attachments (PDF or images, up to 10 MB per file)')" error="attachments.*" for="report-attachments">
            <input id="report-attachments" type="file" wire:model="attachments" multiple accept=".pdf,.jpg,.jpeg,.png" class="ui-control">@error('attachments.*')<span class="sr-only">{{ $message }}</span>@enderror
        </x-ui.field>
        <x-ui.button type="submit">{{ __('Send report') }}</x-ui.button>
    </x-ui.card>
    </form>
    @endif
</div>
