<?php

namespace App\Support\Access;

use App\Filament\Admin\Pages\CompanySettings;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\LocationResource;
use App\Filament\Admin\Resources\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;

class AdminNav
{
    private const ENTRIES = [
        [CompanySettings::class, 'settings', 'heroicon-o-cog-6-tooth'],
        [BranchResource::class, 'folder', 'heroicon-o-building-office'],
        [LocationResource::class, 'folder', 'heroicon-o-map-pin'],
        [UserResource::class, 'users', 'heroicon-o-user-group'],
        [EmployeeResource::class, 'users', 'heroicon-o-user-group'],
    ];

    public static function items(User $user): array
    {
        $panel = Filament::getPanel('admin');
        $tenant = Filament::getCurrentPanel()?->getId() === 'admin'
            ? (Filament::getTenant() ?? $user->company) : $user->company;
        if (! $user->is_active || ! $tenant) {
            return [];
        }

        $previousPanel = Filament::getCurrentPanel();
        $previousTenant = Filament::getTenant();
        Filament::setCurrentPanel($panel);
        Filament::setTenant($tenant, isQuiet: true);

        try {
            if (! $user->canAccessTenant($tenant)) {
                return [];
            }

            $items = [];
            foreach (self::ENTRIES as $sort => [$class, $icon, $filamentIcon]) {
                $registered = is_subclass_of($class, Resource::class)
                    ? in_array($class, $panel->getResources(), true)
                    : in_array($class, $panel->getPages(), true);
                if (! $registered || ! $class::canAccess()) {
                    continue;
                }

                $url = is_subclass_of($class, Resource::class)
                    ? $class::getUrl('index', panel: 'admin', tenant: $tenant)
                    : $class::getUrl(panel: 'admin', tenant: $tenant);
                $items[] = [
                    'label' => $class::getNavigationLabel(),
                    'url' => $url,
                    'icon' => $icon,
                    'filamentIcon' => $filamentIcon,
                    'sort' => $sort,
                    'active' => request()->is(trim(parse_url($url, PHP_URL_PATH), '/'))
                        || request()->is(trim(parse_url($url, PHP_URL_PATH), '/').'/*'),
                ];
            }

            return $items;
        } finally {
            Filament::setTenant($previousTenant, isQuiet: true);
            Filament::setCurrentPanel($previousPanel);
        }
    }
}
