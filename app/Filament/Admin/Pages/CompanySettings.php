<?php

namespace App\Filament\Admin\Pages;

use App\Enums\UserRole;
use App\Models\CompanyLetterhead;
use App\Services\Documents\LetterheadService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class CompanySettings extends Page
{
    public static function getNavigationLabel(): string
    {
        return __('Company settings');
    }

    public function getTitle(): string
    {
        return __('Company settings');
    }

    protected string $view = 'filament.admin.pages.company-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->primary_role, [UserRole::CompanyAdmin, UserRole::PlatformAdmin], true);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $company = Filament::getTenant();
        abort_unless($company, 404);
        $state = [
            'kurdish_enabled' => in_array('ku', $company->enabled_languages, true),
            'requires_second_review' => $company->requires_second_review,
            'show_case_number_on_letters' => $company->show_case_number_on_letters,
            'hotspot_min_cases' => $company->hotspot_min_cases,
            'hotspot_window_days' => $company->hotspot_window_days,
            'internal_regulation_answer' => $company->internal_regulation_answer,
        ];
        foreach ($company->enabled_languages as $language) {
            $letterhead = CompanyLetterhead::query()->where('company_id', $company->id)
                ->where('language_code', $language)->orderByDesc('version')->first();
            foreach (['name', 'address_lines', 'phone', 'email', 'footer_text', 'header_mode'] as $field) {
                $state[$language][$field] = $letterhead?->{$field};
            }
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $fields = [
            Checkbox::make('kurdish_enabled')->label(__('Enable Kurdish (optional)')),
            Checkbox::make('requires_second_review')->label(__('Second review before issuance')),
            Checkbox::make('show_case_number_on_letters')->label(__('Show case number on letters')),
            Radio::make('internal_regulation_answer')->label(__('messages.setup.regulation_question'))
                ->options(['yes' => __('messages.setup.yes'), 'no' => __('messages.setup.no')])->required()->live(),
            TextInput::make('hotspot_min_cases')->label(__('Repeat alert threshold'))->numeric()->integer()->required()->minValue(2)->maxValue(50),
            TextInput::make('hotspot_window_days')->label(__('Repeat alert window in days'))->numeric()->integer()->required()->minValue(7)->maxValue(365),
        ];
        foreach (Filament::getTenant()?->enabled_languages ?? ['ar', 'en'] as $language) {
            $prefix = $language.'.';
            $fields[] = Section::make(strtoupper($language))->schema([
                TextInput::make($prefix.'name')->label(__('Company name (:language)', ['language' => $language]))->required(),
                Textarea::make($prefix.'address_lines')->label(__('Address (:language)', ['language' => $language])),
                TextInput::make($prefix.'phone')->label(__('Phone (:language)', ['language' => $language])),
                TextInput::make($prefix.'email')->label(__('Email (:language)', ['language' => $language]))->email(),
                TextInput::make($prefix.'footer_text')->label(__('Footer (:language)', ['language' => $language]))->required(),
                Select::make($prefix.'header_mode')->label(__('Header mode (:language)', ['language' => $language]))
                    ->options(['logo_text' => __('Logo and text'), 'image' => __('Image')])->required(),
                FileUpload::make($prefix.'logo')->label(__('Logo (:language)', ['language' => $language]))
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])->maxSize(2048)->storeFiles(false),
                FileUpload::make($prefix.'header_image')->label(__('Header image (:language)', ['language' => $language]))
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])->maxSize(2048)->storeFiles(false),
            ]);
        }

        return $schema->statePath('data')->components($fields);
    }

    public function needsReviewLanguages(): array
    {
        $company = Filament::getTenant();
        if (! $company) {
            return [];
        }
        $languages = [];
        foreach ($company->enabled_languages as $language) {
            $latest = CompanyLetterhead::query()->where('company_id', $company->id)
                ->where('language_code', $language)->orderByDesc('version')->first();
            if ($latest?->needs_review) {
                $languages[] = $language;
            }
        }

        return $languages;
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $company = Filament::getTenant();
        abort_unless($company && auth()->user()->can('update', $company), 403);
        $data = $this->form->getState();
        $validated = validator($data, [
            'hotspot_min_cases' => ['required', 'integer', 'between:2,50'],
            'hotspot_window_days' => ['required', 'integer', 'between:7,365'],
            'internal_regulation_answer' => ['required', 'in:yes,no'],
        ])->validate();
        DB::transaction(function () use ($company, $data, $validated): void {
            $company->update([
                'hotspot_min_cases' => $validated['hotspot_min_cases'],
                'hotspot_window_days' => $validated['hotspot_window_days'],
                'enabled_languages' => ['ar', 'en', ...(($data['kurdish_enabled'] ?? false) ? ['ku'] : [])],
                'requires_second_review' => (bool) ($data['requires_second_review'] ?? false),
                'show_case_number_on_letters' => (bool) ($data['show_case_number_on_letters'] ?? false),
                'internal_regulation_answer' => $validated['internal_regulation_answer'],
                'internal_regulation_answered_at' => $company->internal_regulation_answer === $validated['internal_regulation_answer']
                    ? $company->internal_regulation_answered_at : now(),
                'internal_regulation_answered_by' => $company->internal_regulation_answer === $validated['internal_regulation_answer']
                    ? $company->internal_regulation_answered_by : auth()->id(),
            ]);
            foreach ($company->enabled_languages as $language) {
                $settings = $data[$language] ?? [];
                app(LetterheadService::class)->saveVersion($company, $language, [
                    'name' => $settings['name'] ?? $company->name,
                    'address_lines' => $settings['address_lines'] ?? null,
                    'phone' => $settings['phone'] ?? null, 'email' => $settings['email'] ?? null,
                    'footer_text' => $settings['footer_text'] ?? 'CONFIDENTIAL',
                    'header_mode' => $settings['header_mode'] ?? 'logo_text',
                ], auth()->user(), $settings['logo'] ?? null, $settings['header_image'] ?? null);
            }
        });
    }
}
