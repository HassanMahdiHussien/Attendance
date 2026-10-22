<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\CompanySettings;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\LocationResource;
use App\Filament\Admin\Resources\UserResource;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Widgets\Widget;

class AdminShortcuts extends Widget
{
    protected string $view = 'filament.admin.widgets.admin-shortcuts';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Filament::getTenant() !== null && auth()->user()?->canAccessPanel(Filament::getPanel('admin')) === true;
    }

    public function links(): array
    {
        $panel = Filament::getPanel('admin');
        $tenant = Filament::getTenant();
        $links = [];

        foreach ([
            EmployeeResource::class,
            UserResource::class,
            BranchResource::class,
            LocationResource::class,
            CompanySettings::class,
        ] as $class) {
            $resource = is_subclass_of($class, Resource::class);
            $registered = $resource
                ? in_array($class, $panel->getResources(), true)
                : in_array($class, $panel->getPages(), true);

            if (! $registered || ($resource ? ! $class::canViewAny() : ! $class::canAccess())) {
                continue;
            }

            $links[] = [
                'label' => $class::getNavigationLabel(),
                'url' => $resource
                    ? $class::getUrl('index', panel: 'admin', tenant: $tenant)
                    : $class::getUrl(panel: 'admin', tenant: $tenant),
            ];
        }

        $links[] = ['label' => __('Open workspace'), 'url' => '/home'];

        return $links;
    }
}
