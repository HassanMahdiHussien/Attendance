<x-filament-panels::page>
    <div class="space-y-4">
        <section class="flex flex-wrap gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <label class="min-w-0 max-w-full">{{ __('messages.attendance_ui.type') }} <select wire:model.live="type" class="block w-full max-w-full rounded-xl border border-gray-200 p-2 sm:w-auto"><option value="">{{ __('messages.attendance_ui.all') }}</option>@foreach ($this->types() as $issueType)<option value="{{ $issueType }}">{{ __('messages.attendance_import.issue_types.'.$issueType) }}</option>@endforeach</select></label>
            <label class="min-w-0 max-w-full">{{ __('messages.attendance_ui.state') }} <select wire:model.live="status" class="block w-full max-w-full rounded-xl border border-gray-200 p-2 sm:w-auto"><option value="">{{ __('messages.attendance_ui.all') }}</option>@foreach (['open', 'resolved', 'dismissed'] as $state)<option value="{{ $state }}">{{ __('messages.attendance_ui.issue_states.'.$state) }}</option>@endforeach</select></label>
        </section>
        @if ($type === '')
        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">{{ __('messages.attendance_ui.issue_summary') }}</h2>
            <div class="mt-3 divide-y divide-gray-200">
                @forelse ($this->typeCounts() as $group)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="issue-type-{{ $group->type }}">
                        <span>{{ __('messages.attendance_import.issue_types.'.$group->type) }} <bdi class="ms-2 font-mono" dir="ltr">{{ $group->issue_count }}</bdi></span>
                        <button type="button" wire:click="showType(@js($group->type))" class="ui-button ui-button-secondary ui-button-small">{{ __('messages.attendance_ui.show_type') }}</button>
                    </div>
                @empty <p class="py-3 text-gray-600">{{ __('messages.attendance_ui.no_issues') }}</p> @endforelse
            </div>
        </section>
        @else
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">{{ __('messages.attendance_import.issue_types.'.$type) }} <bdi class="ms-2 font-mono" dir="ltr">{{ $this->issues()->total() }}</bdi></h2>
                    <button type="button" wire:click="$set('type', '')" class="ui-button ui-button-ghost ui-button-small">{{ __('messages.attendance_ui.all_types') }}</button>
                </div>
                @if ($status === 'open')
                    <div class="flex flex-wrap items-end gap-3 border-b pb-4">
                        <button type="button" wire:click="selectAllOnPage" class="ui-button ui-button-secondary ui-button-small">{{ __('messages.attendance_ui.select_page') }}</button>
                        <label>{{ __('messages.attendance_ui.note') }} <input type="text" wire:model="bulkNote" class="block rounded-xl border border-gray-200 p-2"></label>
                        @if ($type !== 'pattern_review') <button type="button" wire:click="bulkClose('resolve')" class="ui-button ui-button-secondary ui-button-small">{{ __('messages.attendance_ui.resolve_selected') }}</button> @endif
                        <button type="button" wire:click="bulkClose('dismiss')" class="ui-button ui-button-ghost ui-button-small">{{ __('messages.attendance_ui.dismiss_selected') }}</button>
                    </div>
                    @error('selectedIssueIds') <p class="text-red-700">{{ $message }}</p> @enderror
                    @error('bulkNote') <p class="text-red-700">{{ $message }}</p> @enderror
                @endif
                @forelse ($this->issues() as $issue)
                    <div class="border-b p-2" wire:key="attendance-issue-{{ $issue->id }}">
                        <div class="flex items-start gap-2">
                            @if ($issue->status === 'open') <input type="checkbox" wire:model="selectedIssueIds" value="{{ $issue->id }}" aria-label="{{ __('messages.attendance_ui.select_issue') }} {{ $issue->id }}"> @endif
                            <div><strong>{{ __('messages.attendance_import.issue_types.'.$issue->type) }}</strong>
                                @if ($employee = $this->employeeSubject($issue))
                                    <a class="underline text-primary-700" href="{{ route('employees.show', $employee) }}">{{ $employee->name }} (<bdi dir="ltr">{{ $employee->employee_code }}</bdi>)</a>
                                @elseif ($request = $this->leaveRequestSubject($issue))
                                    <a class="underline text-primary-700" href="{{ \App\Filament\Admin\Pages\Leave\LeaveRequestDetail::getUrl(panel: 'admin', tenant: $this->company()).'?'.http_build_query(['request' => $request->id]) }}">{{ __('messages.attendance_ui.leave_request') }} <bdi dir="ltr">{{ $request->external_id ?: $request->id }}</bdi></a>
                                @else <bdi dir="ltr">{{ $issue->subject_key }}</bdi> @endif
                                — {{ __('messages.attendance_ui.issue_states.'.$issue->status) }}
                            </div>
                        </div>
                        @if ($issue->status === 'open')
                            <div class="mt-2 flex flex-wrap gap-2"><input type="text" wire:model="notes.{{ $issue->id }}" class="rounded-xl border border-gray-200 p-2" placeholder="{{ __('messages.attendance_ui.note') }}">
                                @if ($issue->type !== 'pattern_review') <button type="button" class="ui-button ui-button-secondary ui-button-small" wire:click="close({{ $issue->id }}, &quot;resolve&quot;)">{{ __('messages.attendance_ui.resolve') }}</button> @endif
                                <button type="button" class="ui-button ui-button-ghost ui-button-small" wire:click="close({{ $issue->id }}, &quot;dismiss&quot;)">{{ __('messages.attendance_ui.dismiss') }}</button>
                            </div>
                            @error('notes.'.$issue->id) <p class="text-red-700">{{ $message }}</p> @enderror
                        @else <p>{{ $issue->note }}</p> @endif
                    </div>
                @empty <p>{{ __('messages.attendance_ui.no_issues') }}</p> @endforelse
                {{ $this->issues()->links() }}
            </section>
        @endif
    </div>
</x-filament-panels::page>
