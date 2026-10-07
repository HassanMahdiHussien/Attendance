<?php

namespace App\Filament\Platform\Resources\LawResource\RelationManagers;

use App\LegalLibrary\LawImportService;
use App\Models\LegalImportRun;
use App\Support\Format\ArabicDigits;
use App\Support\Format\LegalLibraryLabels;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImportRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'importRuns';

    // Small lists; load with the page instead of on scroll.
    protected static bool $isLazy = false;

    protected static ?string $title = 'عمليات الاستيراد';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('العملية'),
            TextColumn::make('state')->label('الحالة')->badge()
                ->formatStateUsing(fn ($state): string => LegalLibraryLabels::runState((string) $state))
                ->color(fn ($state): string => LegalLibraryLabels::statusColor((string) $state)),
            TextColumn::make('counts')->label('التقدم')->formatStateUsing(fn ($state): string => LegalLibraryLabels::counts(is_array($state) ? $state : null)),
            TextColumn::make('pages_count')->label('الصفحات')->counts('pages'),
            TextColumn::make('page_statuses')->label('حالة الصفحات')->state(fn (LegalImportRun $record): string => $record->pages()
                ->get(['text_status', 'ai_status'])->groupBy(fn ($page): string => $page->text_status.'/'.$page->ai_status)
                ->map(function ($pages, $state): string {
                    [$text, $ai] = explode('/', $state);

                    return 'النص '.LegalLibraryLabels::pageStatus($text).'، الذكاء الاصطناعي '.LegalLibraryLabels::pageStatus($ai).': '.ArabicDigits::convert($pages->count());
                })->implode('، ')),
            TextColumn::make('error')->label('الخطأ')->wrap(),
            TextColumn::make('started_at')->label('بدأت')->formatStateUsing(fn ($state, LegalImportRun $record): string => LegalLibraryLabels::dateTime($record->started_at)),
            TextColumn::make('finished_at')->label('انتهت')->formatStateUsing(fn ($state, LegalImportRun $record): string => LegalLibraryLabels::dateTime($record->finished_at)),
        ])->recordActions([
            Action::make('retryAi')->label('إعادة قراءة AI')->visible(fn (LegalImportRun $record): bool => in_array($record->state, ['ai_pending', 'completed'], true))
                ->action(function (LegalImportRun $record): void {
                    app(LawImportService::class)->retryAi($record, auth()->user());
                    Notification::make()->title('أُعيدت قراءة الصفحات بالذكاء الاصطناعي')->success()->send();
                }),
            Action::make('cancel')->label('إلغاء')->color('danger')->visible(fn (LegalImportRun $record): bool => ! in_array($record->state, ['completed', 'failed', 'cancelled'], true))
                ->schema([Textarea::make('reason')->label('سبب الإلغاء')->required()])
                ->action(function (LegalImportRun $record, array $data): void {
                    app(LawImportService::class)->cancel($record, auth()->user(), $data['reason']);
                    Notification::make()->title('أُلغيت عملية الاستيراد')->success()->send();
                }),
        ]);
    }
}
