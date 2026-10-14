<?php

namespace App\Filament\Admin\Pages;

use App\Enums\UserRole;
use App\Filament\Concerns\ReviewsLegalMismatch;
use App\LegalLibrary\RegulationApprovals;
use App\LegalLibrary\RegulationUpload;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\LegalUnitApproval;
use App\Support\Format\LegalLibraryLabels;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithFileUploads;

class InternalRegulation extends Page
{
    use ReviewsLegalMismatch;
    use WithFileUploads;

    public static function getNavigationLabel(): string
    {
        return __('Internal regulation');
    }

    public function getTitle(): string
    {
        return __('Internal regulation');
    }

    protected string $view = 'filament.admin.pages.internal-regulation';

    public $sourceFile;

    public string $regulationName = '';

    public ?string $regulationNumber = null;

    public ?string $regulationYear = null;

    public ?int $selectedLawId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->is_active && (in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::PlatformAdmin], true)
            || ($user->company_id !== null && $user->can('review_internal_regulation')));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess() && Filament::getTenant()
            && auth()->user()->canAccessTenant(Filament::getTenant()), 403);
    }

    public function laws(): mixed
    {
        abort_unless(static::canAccess() && Filament::getTenant()
            && auth()->user()->canAccessTenant(Filament::getTenant()), 403);

        return Law::query()->where('kind', 'internal_regulation')
            ->where('company_id', Filament::getTenant()->id)->latest('id')->get();
    }

    public function selectLaw(int $id): void
    {
        $this->selectedLawId = $this->laws()->firstWhere('id', $id)?->id;
        abort_unless($this->selectedLawId, 404);
        $this->selectedUnitId = null;
    }

    public function units(): mixed
    {
        if (! $this->selectedLawId) {
            return collect();
        }

        return LegalUnit::query()->where('law_id', $this->selectedLawId)
            ->whereHas('law', fn (Builder $law) => $law->where('company_id', Filament::getTenant()->id)
                ->where('kind', 'internal_regulation'))
            ->orderBy('id')->get();
    }

    public function lawSummary(Law $law): string
    {
        $counts = $law->units()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $counts['units'] = array_sum($counts);
        $counts['mismatch'] ??= 0;

        return LegalLibraryLabels::counts($counts);
    }

    public function hasPendingApprovals(Law $law): bool
    {
        return $law->units()->whereIn('status', ['verified_auto', 'verified_human', 'verified_text'])
            ->whereNull('superseded_by_id')
            ->whereNotIn('id', LegalUnitApproval::query()->select('legal_unit_id'))->exists();
    }

    public function importCounts(Law $law): ?string
    {
        $counts = $law->importRuns()->latest('id')->first()?->counts;

        return $counts ? LegalLibraryLabels::counts($counts) : null;
    }

    public function upload(): void
    {
        $company = Filament::getTenant();
        abort_unless(static::canAccess() && $company && auth()->user()->primary_role === UserRole::CompanyAdmin
            && (int) auth()->user()->company_id === (int) $company->id, 403);
        $this->validate([
            'sourceFile' => 'required|file|mimes:txt,docx,pdf|max:51200',
            'regulationName' => 'required|string|max:255',
            'regulationNumber' => 'nullable|string|max:255',
            'regulationYear' => 'nullable|integer|min:1900|max:2200',
        ]);
        $law = app(RegulationUpload::class)->upload($company, auth()->user(), $this->sourceFile, [
            'name' => $this->regulationName,
            'number' => $this->regulationNumber,
            'year' => $this->regulationYear,
        ]);
        $this->selectedLawId = $law->id;
        $this->reset('sourceFile', 'regulationName', 'regulationNumber', 'regulationYear');
    }

    public function approveUnit(int $id): void
    {
        $unit = $this->units()->firstWhere('id', $id);
        abort_unless($unit && auth()->user()->can('review', $unit->law), 403);
        app(RegulationApprovals::class)->approve($unit, auth()->user());
        Notification::make()->title(__('Unit approved'))->success()->send();
    }

    public function approveAll(): void
    {
        $law = $this->laws()->firstWhere('id', $this->selectedLawId);
        abort_unless($law && auth()->user()->can('review', $law), 403);
        app(RegulationApprovals::class)->approveAllVerified($law, auth()->user());
        Notification::make()->title(__('Verified units approved'))->success()->send();
    }

    protected function mismatchQuery(): Builder
    {
        abort_unless(static::canAccess() && Filament::getTenant()
            && auth()->user()->canAccessTenant(Filament::getTenant()), 403);

        return LegalUnit::query()->where('status', 'mismatch')
            ->whereHas('law', fn (Builder $law) => $law->where('kind', 'internal_regulation')
                ->where('company_id', Filament::getTenant()->id));
    }
}
