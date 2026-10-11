<x-filament-panels::page>
    <div class="space-y-6">
        <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 class="font-bold">{{ __('Employee file') }}</h2>
            <p>{{ __('Download the template and fill one row per employee. Required columns: name, department, position, hire date, and branch. Employee number, email, and gender are optional. Use 2024-01-15 or 15/01/2024 for dates.') }}</p>
            <p>{{ __('Example: Ahmed Ali, EMP-0100, HR, Officer, 2024-01-15, Baghdad, ahmed@example.com, male. Leave the employee number blank to generate one. Blank optional cells do not erase existing values.') }}</p>
            <a class="underline text-primary-600" href="{{ route('employees.import.template', ['company' => \Filament\Facades\Filament::getTenant()->id]) }}">{{ __('Download CSV template') }}</a>
            <form wire:submit="previewImport" class="space-y-3">
                <input type="file" wire:model="sourceFile" accept=".csv,.txt,text/csv,text/plain" class="block w-full rounded-xl border border-gray-200 bg-white p-2">
                @error('sourceFile') <p class="text-red-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit">{{ __('Preview file') }}</x-filament::button>
            </form>
        </section>

        @if ($summary)
            <section class="rounded-xl border border-green-500 p-4">{{ __('Import completed: :added added, :updated updated.', ['added' => \App\Support\Format\Ui::number($summary['added']), 'updated' => \App\Support\Format\Ui::number($summary['updated'])]) }}</section>
        @endif

        @if ($previewRows !== null)
            @php
                $errors = count(array_filter($previewRows, fn ($row) => $row['status'] === 'error'));
                $added = count(array_filter($previewRows, fn ($row) => $row['status'] === 'new'));
                $updated = count($previewRows) - $errors - $added;
            @endphp
            <section class="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="font-bold">{{ __('Preview: :count rows', ['count' => \App\Support\Format\Ui::number(count($previewRows))]) }}</h2>
                <p>{{ __('New: :new · Updates: :updated · Errors: :errors', ['new' => \App\Support\Format\Ui::number($added), 'updated' => \App\Support\Format\Ui::number($updated), 'errors' => \App\Support\Format\Ui::number($errors)]) }}</p>
                @if ($notices)
                    <p>{{ __('Unknown columns were ignored') }}: {{ implode(', ', $notices) }}</p>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full text-start border-collapse">
                        <thead><tr class="border-b"><th class="p-2">{{ __('Row') }}</th><th class="p-2">{{ __('Status') }}</th><th class="p-2">{{ __('Name') }}</th><th class="p-2">{{ __('Number') }}</th><th class="p-2">{{ __('Branch') }}</th><th class="p-2">{{ __('Changes / notes') }}</th></tr></thead>
                        <tbody>
                            @foreach ($previewRows as $row)
                                <tr class="border-b align-top">
                                    <td class="p-2">{{ \App\Support\Format\Ui::number($row['number']) }}</td>
                                    <td class="p-2"><x-filament::badge :color="$row['status'] === 'error' ? 'danger' : ($row['status'] === 'new' ? 'success' : 'warning')">{{ match ($row['status']) { 'new' => __('New'), 'update' => __('Will be updated'), 'update-inactive' => __('Will be updated (inactive)'), default => __('Error') } }}</x-filament::badge></td>
                                    <td class="p-2">{{ $row['values']['name'] }}</td>
                                    <td class="p-2">{{ $row['values']['employee_code'] ?: __('Will be generated') }}</td>
                                    <td class="p-2">{{ $row['branch'] }}</td>
                                    <td class="p-2">
                                        @foreach ($row['errors'] as $error) <p class="text-red-600">{{ $error }}</p> @endforeach
                                        @if ($row['diff']) <p>{{ __('Change') }}: {{ implode(', ', $row['diff']) }}</p> @endif
                                        @foreach ($row['warnings'] as $warning) <p class="text-amber-600">{{ $warning }}</p> @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-filament::button wire:click="confirmImport" :disabled="$errors > 0">{{ __('Confirm import') }}</x-filament::button>
            </section>
        @endif
    </div>
</x-filament-panels::page>
