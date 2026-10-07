<?php

namespace App\Filament\Platform\Pages;

use App\Enums\UserRole;
use App\Filament\Concerns\ReviewsLegalMismatch;
use App\Models\LegalUnit;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

class MismatchQueue extends Page
{
    use ReviewsLegalMismatch;

    protected static ?string $navigationLabel = 'اختلافات القوانين';

    protected static ?string $title = 'مراجعة اختلافات القوانين';

    protected string $view = 'filament.platform.pages.mismatch-queue';

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active && auth()->user()?->primary_role === UserRole::PlatformAdmin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function mismatchQuery(): Builder
    {
        abort_unless(static::canAccess(), 403);

        return LegalUnit::query()->where('status', 'mismatch')
            ->whereHas('law', fn (Builder $law) => $law->where('kind', 'statute'));
    }
}
