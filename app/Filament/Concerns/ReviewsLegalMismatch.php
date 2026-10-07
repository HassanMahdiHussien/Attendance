<?php

namespace App\Filament\Concerns;

use App\LegalLibrary\MismatchResolver;
use App\LegalLibrary\WordDiff;
use App\Models\LegalUnit;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

trait ReviewsLegalMismatch
{
    public ?int $selectedUnitId = null;

    public string $editedText = '';

    public string $excludeReason = '';

    abstract protected function mismatchQuery(): Builder;

    public function mismatches(): mixed
    {
        return $this->mismatchQuery()->with('law')->orderBy('id')->get();
    }

    public function selectedUnit(): ?LegalUnit
    {
        return $this->selectedUnitId === null ? null : $this->mismatchQuery()
            ->with(['law', 'importRun.pages'])->find($this->selectedUnitId);
    }

    public function selectUnit(int $id): void
    {
        $unit = $this->mismatchQuery()->findOrFail($id);
        abort_unless(auth()->user()->can('review', $unit->law), 403);
        $this->selectedUnitId = $unit->id;
        $this->editedText = $unit->extraction_b ?: ($unit->extraction_a ?? '');
        $this->excludeReason = '';
    }

    public function diff(): array
    {
        $unit = $this->selectedUnit();

        return $unit ? app(WordDiff::class)->diff($unit->extraction_a ?? '', $unit->extraction_b ?? '') : [];
    }

    public function resolveUnit(string $choice): void
    {
        $unit = $this->selectedUnit();
        abort_unless($unit && auth()->user()->can('review', $unit->law), 403);
        app(MismatchResolver::class)->resolve($unit, auth()->user(), $choice, $choice === 'edit' ? $this->editedText : null);
        $this->selectedUnitId = null;
        Notification::make()->title($choice === 'edit' ? 'تم اعتماد النص المصحح' : 'تم اعتماد القراءة')->success()->send();
    }

    public function excludeUnit(): void
    {
        $unit = $this->selectedUnit();
        abort_unless($unit && auth()->user()->can('review', $unit->law), 403);
        app(MismatchResolver::class)->exclude($unit, auth()->user(), $this->excludeReason);
        $this->selectedUnitId = null;
        Notification::make()->title('تم استبعاد الوحدة')->success()->send();
    }
}
