<?php

namespace App\Filament\Platform\Resources\LawResource\RelationManagers;

use App\LegalLibrary\LawImportService;
use App\Models\LegalImportRun;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
            TextColumn::make('state')->label('الحالة')->badge(),
            TextColumn::make('counts')->label('التقدم')->formatStateUsing(fn ($state): string => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : '—'),
            TextColumn::make('pages_count')->label('الصفحات')->counts('pages'),
            TextColumn::make('page_statuses')->label('حالة الصفحات')->state(fn (LegalImportRun $record): string => $record->pages()
                ->get(['text_status', 'ai_status'])->groupBy(fn ($page): string => $page->text_status.'/'.$page->ai_status)
                ->map(fn ($pages, $state): string => $state.': '.$pages->count())->implode('، ')),
            TextColumn::make('error')->label('الخطأ')->wrap(),
            TextColumn::make('started_at')->label('بدأت')->dateTime(),
        ])->recordActions([
            Action::make('retryAi')->label('إعادة قراءة AI')->visible(fn (LegalImportRun $record): bool => in_array($record->state, ['ai_pending', 'completed'], true))
                ->action(fn (LegalImportRun $record) => app(LawImportService::class)->retryAi($record, auth()->user())),
            Action::make('cancel')->label('إلغاء')->color('danger')->visible(fn (LegalImportRun $record): bool => ! in_array($record->state, ['completed', 'failed', 'cancelled'], true))
                ->schema([Textarea::make('reason')->label('سبب الإلغاء')->required()])
                ->action(fn (LegalImportRun $record, array $data) => app(LawImportService::class)->cancel($record, auth()->user(), $data['reason'])),
        ]);
    }
}
