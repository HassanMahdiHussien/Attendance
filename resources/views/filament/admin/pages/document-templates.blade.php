<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex flex-wrap gap-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <label class="flex min-w-48 flex-col gap-1 text-sm font-bold">{{ __('Document type') }}
                <select wire:model.live="documentType" class="min-h-10 rounded-xl border border-gray-200 bg-white px-3">
                    <option value="defense_letter">{{ __('Defense request') }}</option>
                    <option value="recommendations">{{ __('Committee recommendations') }}</option>
                    <option value="decision_letter">{{ __('Decision letter') }}</option>
                </select>
            </label>
            <label class="flex min-w-48 flex-col gap-1 text-sm font-bold">{{ __('Language') }}
                <select wire:model.live="language" class="min-h-10 rounded-xl border border-gray-200 bg-white px-3">
                    @foreach (\Filament\Facades\Filament::getTenant()->enabled_languages as $code)
                        <option value="{{ $code }}">{{ ['ar' => __('Arabic'), 'en' => __('English'), 'ku' => __('Kurdish')][$code] ?? $code }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        @if ($this->needsCompanyWording())
            <p role="alert" class="rounded-lg border border-amber-400 bg-amber-50 p-3 text-amber-900">{{ __('There is no default wording for this language. The company must provide template text before use.') }}</p>
        @endif
        <p class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">{{ __('Available placeholders') }}: @foreach (\App\Documents\TemplateValidator::PLACEHOLDERS as $placeholder) <code>&#123;{{ $placeholder }}&#125;</code> @endforeach</p>
        <p>{{ __('What will be printed, excluding the temporary reference number and draft mark.') }}</p>
        <form wire:key="template-{{ $documentType }}-{{ $language }}" wire:submit="save" class="space-y-4">
            {{ $this->form }}
            @if ($this->canSave())
                <x-filament::button type="submit">{{ __('Save new version') }}</x-filament::button>
            @endif
        </form>
        <h2>{{ __('Preview') }}</h2>
        <iframe title="{{ __('Template preview') }}" sandbox srcdoc="{{ $this->previewHtml() }}" class="w-full rounded-2xl border border-gray-200 bg-white shadow-sm" style="height:460px"></iframe>
        <h2>{{ __('Version history') }}</h2>
        <ul>
            @foreach ($this->history() as $version)
                <li>{{ __('Version') }} {{ \App\Support\Format\Ui::number($version['version']) }} — {{ \App\Support\Format\Ui::date($version['created_at']) }}</li>
            @endforeach
        </ul>
    </div>
</x-filament-panels::page>
