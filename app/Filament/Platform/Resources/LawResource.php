<?php

namespace App\Filament\Platform\Resources;

use App\Enums\Jurisdiction;
use App\Enums\UserRole;
use App\Filament\Platform\Resources\LawResource\Pages;
use App\Filament\Platform\Resources\LawResource\RelationManagers;
use App\Models\Law;
use App\Support\Format\ArabicDigits;
use App\Support\Format\LegalLibraryLabels;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LawResource extends Resource
{
    protected static ?string $model = Law::class;

    protected static ?string $modelLabel = 'قانون';

    protected static ?string $pluralModelLabel = 'المكتبة القانونية';

    protected static bool $isGloballySearchable = false;

    public static function canViewAny(): bool
    {
        return auth()->user()?->is_active && auth()->user()?->primary_role === UserRole::PlatformAdmin;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->kind->value === 'statute';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', 'statute');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم القانون')->required()->maxLength(255),
            TextInput::make('name_en')->label('اسم القانون بالإنجليزية')->maxLength(255),
            TextInput::make('number')->label('الرقم')->required(),
            TextInput::make('year')->label('السنة')->numeric()->required(),
            Select::make('category')->label('التصنيف')->options(['labor' => 'عمل', 'social_security' => 'ضمان اجتماعي', 'other' => 'أخرى'])->required(),
            Select::make('approval_status')->label('اعتماد القانون')->options(['pending' => 'بانتظار الاعتماد', 'approved' => 'معتمد'])
                ->default('pending')->required(),
            TextInput::make('source_file_path')->label('مسار المصدر')->required(),
            DatePicker::make('effective_from')->label('تاريخ النفاذ'),
            DatePicker::make('repealed_at')->label('تاريخ الإلغاء'),
            Select::make('supersedes_law_id')->label('يحل محل')->options(fn (): array => Law::query()->where('kind', 'statute')->pluck('name', 'id')->all())->searchable(),
            Select::make('jurisdiction_values')->label('نطاق التطبيق')->multiple()->options([
                Jurisdiction::FederalIraq->value => 'العراق الاتحادي',
                Jurisdiction::KurdistanRegion->value => 'إقليم كردستان',
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('القانون')->searchable(),
            TextColumn::make('name_en')->label('الاسم بالإنجليزية')->toggleable(),
            TextColumn::make('number')->label('الرقم'),
            TextColumn::make('year')->label('السنة'),
            TextColumn::make('category')->label('التصنيف')->formatStateUsing(fn ($state): string => LegalLibraryLabels::category($state instanceof \BackedEnum ? $state->value : (string) $state)),
            TextColumn::make('jurisdictions.jurisdiction')->label('نطاق التطبيق')->badge()
                ->formatStateUsing(fn ($state): string => LegalLibraryLabels::jurisdiction($state instanceof \BackedEnum ? $state->value : (string) $state)),
            TextColumn::make('effective_from')->label('النفاذ')->date(),
            TextColumn::make('repealed_at')->label('الإلغاء')->date(),
            TextColumn::make('units_count')->label('الوحدات')->counts('units'),
            TextColumn::make('verified_count')->label('موثقة')->state(fn (Law $record): int => $record->units()->whereIn('status', ['verified_auto', 'verified_human', 'verified_text'])->count()),
            TextColumn::make('mismatch_count')->label('اختلاف')->state(fn (Law $record): int => $record->units()->where('status', 'mismatch')->count()),
            TextColumn::make('status_counts')->label('الحالات')->state(fn (Law $record): string => $record->units()
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
                ->map(fn ($count, $status): string => LegalLibraryLabels::unitStatus($status).': '.ArabicDigits::convert((int) $count))->implode('، ')),
            TextColumn::make('latest_run')->label('آخر استيراد')->state(fn (Law $record): string => ($state = $record->importRuns()->latest('id')->value('state')) ? LegalLibraryLabels::runState($state) : '—'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ImportRunsRelationManager::class, RelationManagers\UnitsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLaws::route('/'),
            'create' => Pages\CreateLaw::route('/create'),
            'edit' => Pages\EditLaw::route('/{record}/edit'),
        ];
    }
}
