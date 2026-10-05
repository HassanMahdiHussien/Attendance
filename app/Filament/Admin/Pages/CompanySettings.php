<?php

namespace App\Filament\Admin\Pages;

use App\Enums\UserRole;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

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
        $this->form->fill([
            'letterhead_logo_path' => $company->letterhead_logo_path,
            'letterhead_address' => $company->letterhead_address,
            'letterhead_phone' => $company->letterhead_phone,
            'letterhead_email' => $company->letterhead_email,
            'kurdish_enabled' => in_array('ku', $company->enabled_languages, true),
            'requires_second_review' => $company->requires_second_review,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('letterhead_logo_path')->label('مسار الشعار'),
            TextInput::make('letterhead_address')->label('عنوان الترويسة'),
            TextInput::make('letterhead_phone')->label('هاتف الترويسة'),
            TextInput::make('letterhead_email')->label('بريد الترويسة')->email(),
            Checkbox::make('kurdish_enabled')->label('إضافة الكردية (اختياري)'),
            Checkbox::make('requires_second_review')->label('مراجعة ثانية قبل الإصدار'),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $company = Filament::getTenant();
        abort_unless($company && auth()->user()->can('update', $company), 403);
        $data = $this->form->getState();
        $company->update([
            'letterhead_logo_path' => $data['letterhead_logo_path'] ?? null,
            'letterhead_address' => $data['letterhead_address'] ?? null,
            'letterhead_phone' => $data['letterhead_phone'] ?? null,
            'letterhead_email' => $data['letterhead_email'] ?? null,
            'enabled_languages' => ['ar', 'en', ...(($data['kurdish_enabled'] ?? false) ? ['ku'] : [])],
            'requires_second_review' => (bool) ($data['requires_second_review'] ?? false),
        ]);
    }
}
