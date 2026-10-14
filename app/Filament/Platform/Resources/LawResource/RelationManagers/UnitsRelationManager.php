<?php

namespace App\Filament\Platform\Resources\LawResource\RelationManagers;

use App\LegalLibrary\LawManagement;
use App\LegalLibrary\MismatchResolver;
use App\LegalLibrary\UnitCorrection;
use App\Models\LegalUnit;
use App\Models\User;
use App\Support\Format\LegalLibraryLabels;
use App\Support\Format\Ui;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    // Small lists; load with the page instead of on scroll.
    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Articles and clauses');
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('article_number')->label(__('Article'))->sortable(),
            TextColumn::make('clause_path')->label(__('Clause')),
            TextColumn::make('status')->label(__('Status'))->badge()
                ->formatStateUsing(fn ($state): string => LegalLibraryLabels::unitStatus($state instanceof \BackedEnum ? $state->value : (string) $state))
                ->color(fn ($state): string => LegalLibraryLabels::statusColor($state instanceof \BackedEnum ? $state->value : (string) $state)),
            TextColumn::make('version')->label(__('Version')),
            TextColumn::make('topics.topic')->label(__('Topics'))->badge(),
            TextColumn::make('source_page')->label(__('Page image'))
                ->url(fn (LegalUnit $record): ?string => $record->source_page !== null && $record->import_run_id !== null
                    ? route('legal-library.pages.show', ['run' => $record->import_run_id, 'page' => $record->source_page]) : null)
                ->openUrlInNewTab(),
        ])->recordActions([
            Action::make('viewText')->label(__('Text and history'))->modalContent(fn (LegalUnit $record): HtmlString => $this->historyHtml($record)),
            Action::make('topics')->label(__('Topics'))->visible(fn (LegalUnit $record): bool => $record->superseded_by_id === null)
                ->schema([TextInput::make('topics')->label(__('Topics, separated by commas'))
                    ->helperText(__('Use discipline for disciplinary provisions. Add other topics separated by commas.'))
                    ->default(fn (LegalUnit $record): string => $record->topics()->pluck('topic')->implode(', '))])
                ->action(function (LegalUnit $record, array $data): void {
                    $topics = array_filter(array_map('trim', preg_split('/[,،]/u', $data['topics'] ?? '')));
                    app(LawManagement::class)->setUnitTopics($record, auth()->user(), $topics);
                    Notification::make()->title(__('Topics saved'))->success()->send();
                }),
            Action::make('correct')->label(__('Correct'))->visible(fn (LegalUnit $record): bool => in_array($record->status->value, ['verified_auto', 'verified_human', 'verified_text', 'verified_single'], true) && $record->superseded_by_id === null)
                ->schema([
                    Textarea::make('text')->label(__('Official text'))->required()->default(fn (LegalUnit $record): string => $record->official_text),
                    Textarea::make('reason')->label(__('Correction reason'))->required(),
                ])->action(function (LegalUnit $record, array $data): void {
                    app(UnitCorrection::class)->correct($record, auth()->user(), $data['text'], $data['reason']);
                    Notification::make()->title(__('Unit corrected'))->success()->send();
                }),
            Action::make('exclude')->label(__('Exclude'))->color('danger')->visible(fn (LegalUnit $record): bool => in_array($record->status->value, ['mismatch', 'unverified'], true))
                ->schema([Textarea::make('reason')->label(__('Exclusion reason'))->required()])
                ->action(function (LegalUnit $record, array $data): void {
                    app(MismatchResolver::class)->exclude($record, auth()->user(), $data['reason']);
                    Notification::make()->title(__('Unit excluded'))->success()->send();
                }),
        ]);
    }

    private function historyHtml(LegalUnit $record): HtmlString
    {
        $versions = LegalUnit::query()->where('law_id', $record->law_id)
            ->where('article_number', $record->article_number)
            ->where('clause_path', $record->clause_path)->orderBy('version')->get();
        $html = '<div dir="'.(app()->getLocale() === 'ar' ? 'rtl' : 'ltr').'" style="font-family:Amiri,serif">';
        foreach ($versions as $version) {
            $sources = $version->tags ?? [];
            $html .= '<section><strong>'.e(__('Version')).' '.e(Ui::number($version->version)).'</strong>';
            if (isset($sources['reading_a_source']) || isset($sources['reading_b_source'])) {
                $html .= '<p>'.e(__('Reading sources')).': '
                    .e(LegalLibraryLabels::textMethod((string) ($sources['reading_a_source'] ?? ''))).' / '
                    .e(LegalLibraryLabels::textMethod((string) ($sources['reading_b_source'] ?? ''))).'</p>';
                foreach (['reading_a_uploaded_by', 'reading_b_uploaded_by'] as $uploader) {
                    if ($sources[$uploader] ?? null) {
                        $html .= '<p>'.e(__('Uploaded by')).': '
                            .e(User::find($sources[$uploader])?->name ?? __('Unknown user')).'</p>';
                    }
                }
            }
            $html .= '<p dir="rtl" style="white-space:pre-wrap">'.e($version->official_text).'</p></section>';
        }

        return new HtmlString($html.'</div>');
    }
}
