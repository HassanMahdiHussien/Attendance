<?php

namespace App\Filament\Admin\Resources\Leave;

use App\Enums\UserRole;
use App\Models\Company;
use Filament\Facades\Filament;
use Filament\Pages\Page;

abstract class LeavePage extends Page
{
    public static function getNavigationGroup(): ?string
    {
        return __('messages.leave_ui.navigation');
    }

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

    public function errorLabel(string $code): string
    {
        foreach (['_required' => 'required', '_too_long' => 'too_long'] as $suffix => $kind) {
            if (str_ends_with($code, $suffix)) {
                $field = substr($code, 0, -strlen($suffix));

                return __('messages.leave_ui.row_errors.'.$kind, ['field' => __('messages.leave_ui.fields.'.$field)]);
            }
        }

        return __('messages.leave_ui.row_errors.'.$code);
    }
}
