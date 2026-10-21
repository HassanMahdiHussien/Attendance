<?php

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Filament\Platform\Resources\CompanyResource;
use App\Filament\Platform\Resources\PlatformUserResource;
use App\Models\User;

class PlatformNav
{
    public static function items(User $user): array
    {
        if (! $user->is_active || $user->primary_role !== UserRole::PlatformAdmin) {
            return [];
        }

        $entries = [
            [__('Dashboard'), '/platform'],
            [CompanyResource::getPluralModelLabel(), CompanyResource::getUrl('index', panel: 'platform')],
            [PlatformUserResource::getPluralModelLabel(), PlatformUserResource::getUrl('index', panel: 'platform')],
        ];

        return array_map(function (array $entry): array {
            $path = trim(parse_url($entry[1], PHP_URL_PATH), '/');

            return [
                'label' => $entry[0],
                'url' => $entry[1],
                'active' => request()->is($path) || ($path !== 'platform' && request()->is($path.'/*')),
            ];
        }, $entries);
    }
}
