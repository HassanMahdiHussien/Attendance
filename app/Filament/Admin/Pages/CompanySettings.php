<?php

namespace App\Filament\Admin\Pages;

use App\Enums\UserRole;
use App\Models\CompanyLetterhead;
use App\Services\Documents\LetterheadService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class CompanySettings extends Page
{
    protected static ?string $navigationLabel = 'إعدادات الشركة';

    protected static ?string $title = 'إعدادات الشركة';

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
            Checkbox::make('kurdish_enabled')->label('إضافة الكردية (اختياري)'),
            Checkbox::make('requires_second_review')->label('مراجعة ثانية قبل الإصدار'),
        ];
        foreach (Filament::getTenant()?->enabled_languages ?? ['ar', 'en'] as $language) {
            $prefix = $language.'.';
            $fields[] = Section::make(strtoupper($language))->schema([
                TextInput::make($prefix.'name')->label("اسم الشركة ({$language})")->required(),
                Textarea::make($prefix.'address_lines')->label("العنوان ({$language})"),
                TextInput::make($prefix.'phone')->label("الهاتف ({$language})"),
                TextInput::make($prefix.'email')->label("البريد ({$language})")->email(),
                TextInput::make($prefix.'footer_text')->label("التذييل ({$language})")->required(),
                Select::make($prefix.'header_mode')->label("نوع الترويسة ({$language})")
                    ->options(['logo_text' => 'شعار ونص', 'image' => 'صورة'])->required(),
                FileUpload::make($prefix.'logo')->label("الشعار ({$language})")
                    ->acceptedFileTypes(['image/png', 'image/jpeg'])->maxSize(2048)->storeFiles(false),
                FileUpload::make($prefix.'header_image')->label("صورة الترويسة ({$language})")
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
        DB::transaction(function () use ($company, $data): void {
            $company->update([
                'enabled_languages' => ['ar', 'en', ...(($data['kurdish_enabled'] ?? false) ? ['ku'] : [])],
                'requires_second_review' => (bool) ($data['requires_second_review'] ?? false),
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
