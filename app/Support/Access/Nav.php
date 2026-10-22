<?php

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Models\User;

class Nav
{
    public static function items(User $user): array
    {
        $key = 'workspace_nav_'.$user->id;
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }

        $items = [];
        if ($user->primary_role === UserRole::HrStaff) {
            $items[] = self::item('Home', '/home', 'home', 'home');
            $items[] = self::item('Employees', '/employees', 'users', 'employees*');
        } elseif ($user->primary_role === UserRole::PlatformAdmin) {
            $items[] = self::item('Employees', '/employees', 'users', 'employees*');
        }
        request()->attributes->set($key, $items);

        return $items;
    }

    private static function item(string $label, string $url, string $icon, string $pattern): array
    {
        return compact('label', 'url', 'icon', 'pattern') + ['count' => null, 'dot' => false];
    }
}
