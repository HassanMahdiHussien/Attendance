<?php

namespace App\Filament\Platform\Widgets;

use App\Enums\UserRole;
use App\Filament\Platform\Resources\CompanyResource;
use App\Models\Company;
use Filament\Widgets\Widget;

class CompaniesOverview extends Widget
{
    protected string $view = 'filament.platform.widgets.companies-overview';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->primary_role === UserRole::PlatformAdmin;
    }

    public function companies(): array
    {
        abort_unless(static::canView(), 403);

        return Company::query()->orderBy('name')->get()->map(fn (Company $company) => [
            'name' => $company->name, 'active' => $company->is_active,
            'subscription' => $company->subscription_status->value,
            'url' => CompanyResource::getUrl('edit', ['record' => $company->id]),
        ])->all();
    }
}
