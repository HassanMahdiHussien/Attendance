<?php

namespace App\Support\Access;

use App\Models\User;
use Filament\Facades\Filament;

class WorkspaceTenants
{
    public static function items(User $user): array
    {
        $panel = Filament::getPanel('admin');
        if (! $user->canAccessPanel($panel)) {
            return [];
        }

        $tenants = $user->getTenants($panel);
        if (count($tenants) < 2) {
            return [];
        }

        return collect($tenants)->filter(fn ($tenant): bool => $user->canAccessTenant($tenant))
            ->map(fn ($tenant): array => [
                'name' => $tenant->name,
                'url' => $panel->getUrl($tenant),
            ])->values()->all();
    }
}
