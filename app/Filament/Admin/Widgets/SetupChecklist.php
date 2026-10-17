<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\UserRole;
use App\Services\Companies\CompanySetup;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class SetupChecklist extends Widget
{
    protected string $view = 'filament.admin.widgets.setup-checklist';

    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $company = Filament::getTenant();

        return $company !== null
            && in_array(auth()->user()?->primary_role, [UserRole::CompanyAdmin, UserRole::PlatformAdmin], true)
            && ! app(CompanySetup::class)->complete($company);
    }

    public function items(): array
    {
        return app(CompanySetup::class)->for(Filament::getTenant());
    }
}
