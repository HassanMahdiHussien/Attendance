<?php

namespace App\Filament\Platform\Resources\LawResource\RelationManagers;

use App\LegalLibrary\LawImportService;
use App\Models\LegalImportRun;
use App\Support\Format\LegalLibraryLabels;
use App\Support\Format\Ui;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ImportRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'importRuns';

    // Small lists; load with the page instead of on scroll.
    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Import runs');
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('Operation')),
            TextColumn::make('state')->label(__('Status'))->badge()
                ->formatStateUsing(fn ($state): string => LegalLibraryLabels::runState((string) $state))
                ->color(fn ($state): string => LegalLibraryLabels::statusColor((string) $state)),
            TextColumn::make('counts')->label(__('Progress'))->formatStateUsing(fn ($state): string => LegalLibraryLabels::counts(is_array($state) ? $state : null)),
            TextColumn::make('pages_count')->label(__('Pages'))->counts('pages'),
            TextColumn::make('page_statuses')->label(__('Page status'))->state(fn (LegalImportRun $record): string => $record->pages()
                ->get(['text_status', 'ai_status'])->groupBy(fn ($page): string => $page->text_status.'/'.$page->ai_status)
                ->map(function ($pages, $state): string {
                    [$text, $ai] = explode('/', $state);

                    return __('Text').': '.LegalLibraryLabels::pageStatus($text).__(', ').__('AI').': '.LegalLibraryLabels::pageStatus($ai).': '.Ui::number($pages->count());
                })->implode(__(', '))),
            TextColumn::make('error')->label(__('Error'))->wrap(),
            TextColumn::make('started_at')->label(__('Started'))->formatStateUsing(fn ($state, LegalImportRun $record): string => LegalLibraryLabels::dateTime($record->started_at)),
            TextColumn::make('finished_at')->label(__('Finished'))->formatStateUsing(fn ($state, LegalImportRun $record): string => LegalLibraryLabels::dateTime($record->finished_at)),
        ])->recordActions([
            Action::make('retryAi')->label(__('Reread with AI'))->visible(fn (LegalImportRun $record): bool => in_array($record->state, ['ai_pending', 'completed'], true))
                ->action(function (LegalImportRun $record): void {
                    app(LawImportService::class)->retryAi($record, auth()->user());
                    Notification::make()->title(__('Pages were reread with AI'))->success()->send();
                }),
            Action::make('cancel')->label(__('Cancel'))->color('danger')->visible(fn (LegalImportRun $record): bool => ! in_array($record->state, ['completed', 'failed', 'cancelled'], true))
                ->schema([Textarea::make('reason')->label(__('Cancellation reason'))->required()])
                ->action(function (LegalImportRun $record, array $data): void {
                    app(LawImportService::class)->cancel($record, auth()->user(), $data['reason']);
                    Notification::make()->title(__('Import was cancelled'))->success()->send();
                }),
        ]);
    }
}
