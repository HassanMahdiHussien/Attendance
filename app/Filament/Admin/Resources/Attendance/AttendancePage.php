<?php

namespace App\Filament\Admin\Resources\Attendance;

use App\Enums\UserRole;
use App\Models\Company;
use Filament\Facades\Filament;
use Filament\Pages\Page;

abstract class AttendancePage extends Page
{
    public static function canAccess(): bool
    {
        $company = Filament::getTenant();
        $user = auth()->user();

        return $company instanceof Company && $user?->is_active
            && in_array($user->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && $user->canAccessTenant($company);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function company(): Company
    {
        abort_unless(static::canAccess(), 403);

        return Filament::getTenant();
    }

    public function minutes(?int $value): string
    {
        return $value === null ? '—' : sprintf('%d:%02d', intdiv($value, 60), $value % 60);
    }
}
