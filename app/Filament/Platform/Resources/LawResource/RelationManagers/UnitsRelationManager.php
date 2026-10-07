<?php

namespace App\Filament\Platform\Resources\LawResource\RelationManagers;

use App\LegalLibrary\LawManagement;
use App\LegalLibrary\MismatchResolver;
use App\LegalLibrary\UnitCorrection;
use App\Models\LegalUnit;
use App\Support\Format\LegalLibraryLabels;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    // Small lists; load with the page instead of on scroll.
    protected static bool $isLazy = false;

    protected static ?string $title = 'المواد والفقرات';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('article_number')->label('المادة')->sortable(),
            TextColumn::make('clause_path')->label('الفقرة'),
            TextColumn::make('status')->label('الحالة')->badge()
                ->formatStateUsing(fn ($state): string => LegalLibraryLabels::unitStatus($state instanceof \BackedEnum ? $state->value : (string) $state))
                ->color(fn ($state): string => LegalLibraryLabels::statusColor($state instanceof \BackedEnum ? $state->value : (string) $state)),
            TextColumn::make('version')->label('الإصدار'),
            TextColumn::make('topics.topic')->label('الموضوعات')->badge(),
            TextColumn::make('source_page')->label('صورة الصفحة')
                ->url(fn (LegalUnit $record): ?string => $record->source_page !== null && $record->import_run_id !== null
                    ? route('legal-library.pages.show', ['run' => $record->import_run_id, 'page' => $record->source_page]) : null)
                ->openUrlInNewTab(),
        ])->recordActions([
            Action::make('viewText')->label('النص والتاريخ')->modalContent(fn (LegalUnit $record): HtmlString => $this->historyHtml($record)),
            Action::make('topics')->label('الموضوعات')->visible(fn (LegalUnit $record): bool => $record->superseded_by_id === null)
                ->schema([TextInput::make('topics')->label('الموضوعات، مفصولة بفاصلة')
                    ->helperText('استخدم discipline لمواد الانضباط. يمكن إضافة موضوعات أخرى مفصولة بفاصلة.')
                    ->default(fn (LegalUnit $record): string => $record->topics()->pluck('topic')->implode(', '))])
                ->action(function (LegalUnit $record, array $data): void {
                    $topics = array_filter(array_map('trim', preg_split('/[,،]/u', $data['topics'] ?? '')));
                    app(LawManagement::class)->setUnitTopics($record, auth()->user(), $topics);
                    Notification::make()->title('حُفظت الموضوعات')->success()->send();
                }),
            Action::make('correct')->label('تصحيح')->visible(fn (LegalUnit $record): bool => in_array($record->status->value, ['verified_auto', 'verified_human', 'verified_text'], true) && $record->superseded_by_id === null)
                ->schema([
                    Textarea::make('text')->label('النص الرسمي')->required()->default(fn (LegalUnit $record): string => $record->official_text),
                    Textarea::make('reason')->label('سبب التصحيح')->required(),
                ])->action(function (LegalUnit $record, array $data): void {
                    app(UnitCorrection::class)->correct($record, auth()->user(), $data['text'], $data['reason']);
                    Notification::make()->title('تم تصحيح الوحدة')->success()->send();
                }),
            Action::make('exclude')->label('استبعاد')->color('danger')->visible(fn (LegalUnit $record): bool => in_array($record->status->value, ['mismatch', 'unverified'], true))
                ->schema([Textarea::make('reason')->label('سبب الاستبعاد')->required()])
                ->action(function (LegalUnit $record, array $data): void {
                    app(MismatchResolver::class)->exclude($record, auth()->user(), $data['reason']);
                    Notification::make()->title('تم استبعاد الوحدة')->success()->send();
                }),
        ]);
    }

    private function historyHtml(LegalUnit $record): HtmlString
    {
        $versions = LegalUnit::query()->where('law_id', $record->law_id)
            ->where('article_number', $record->article_number)
            ->where('clause_path', $record->clause_path)->orderBy('version')->get();
        $html = '<div dir="rtl" style="font-family:Amiri,serif">';
        foreach ($versions as $version) {
            $html .= '<section><strong>الإصدار '.e((string) $version->version).'</strong><p style="white-space:pre-wrap">'.e($version->official_text).'</p></section>';
        }

        return new HtmlString($html.'</div>');
    }
}
