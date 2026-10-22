<?php

namespace App\Filament\Admin\Pages;

use App\Enums\UserRole;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

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
        $company = Filament::getTenant();
        $user = auth()->user();

        return $company !== null
            && in_array($user?->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && $user->canAccessTenant($company);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $company = Filament::getTenant();
        abort_unless($company, 404);
        $this->form->fill(['kurdish_enabled' => in_array('ku', $company->enabled_languages, true)]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Checkbox::make('kurdish_enabled')->label(__('Enable Kurdish (optional)')),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $company = Filament::getTenant();
        abort_unless($company && auth()->user()->can('update', $company), 403);
        $data = $this->form->getState();
        $company->update(['enabled_languages' => ['ar', 'en', ...(($data['kurdish_enabled'] ?? false) ? ['ku'] : [])]]);
    }
}
