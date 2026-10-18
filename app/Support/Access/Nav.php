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

        $role = $user->primary_role;
        $items = [];
        if (! in_array($role, [UserRole::PlatformAdmin, UserRole::Reporter, UserRole::DeptManager], true)) {
            $items[] = self::item('My work', '/home', 'home', 'home');
        }
        if (in_array($role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)) {
            if (DashboardNav::visible($user)) {
                $items[] = self::item('Dashboard', '/dashboard', 'chart', 'dashboard*');
            }
            $items[] = self::item('Cases', '/cases', 'folder', 'cases*');
            $count = ViolationReportVisibility::inbox($user)->count() + EmailDraftVisibility::inbox($user)->count();
            $items[] = self::item('Reports inbox', '/reports/inbox', 'inbox', 'reports/inbox', $count);
        } elseif (in_array($role, [UserRole::Reporter, UserRole::DeptManager], true)) {
            $items[] = self::item('My reports', '/reports', 'inbox', 'reports*');
        } elseif (in_array($role, [UserRole::CommitteeMember, UserRole::ExternalLegalReviewer], true)) {
            $items[] = self::item('My cases', '/my-cases', 'folder', 'my-cases');
        }

        if (CommitteeNav::hasPending($user)) {
            $items[] = self::item('Committee', '/committee', 'check', 'committee*', null, true);
        }
        if (in_array($role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::PlatformAdmin, UserRole::HrPartnerStaff], true)) {
            $items[] = self::item('Statistics', '/statistics', 'chart', 'statistics*');
            $items[] = self::item('Employees', '/employees', 'users', 'employees*');
        }
        request()->attributes->set($key, $items);

        return $items;
    }

    private static function item(string $label, string $url, string $icon, string $pattern, ?int $count = null, bool $dot = false): array
    {
        return compact('label', 'url', 'icon', 'pattern', 'count', 'dot');
    }
}
