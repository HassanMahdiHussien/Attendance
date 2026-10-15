<?php

namespace App\Filament\Platform\Resources;

use App\Enums\Jurisdiction;
use App\Enums\UserRole;
use App\Filament\Platform\Resources\LawResource\Pages;
use App\Filament\Platform\Resources\LawResource\RelationManagers;
use App\Models\Law;
use App\Support\Format\LegalLibraryLabels;
use App\Support\Format\Ui;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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

    public static function getModelLabel(): string
    {
        return __('Law');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Legal library');
    }

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
            TextInput::make('name')->label(__('Law name'))->required()->maxLength(255),
            TextInput::make('name_en')->label(__('English law name'))->maxLength(255),
            TextInput::make('number')->label(__('Number'))->required(),
            TextInput::make('year')->label(__('Year'))->numeric()->required(),
            Select::make('category')->label(__('Category'))->options(['labor' => __('Labor'), 'social_security' => __('Social security'), 'other' => __('Other')])->required(),
            Select::make('approval_status')->label(__('Law approval'))->options(['pending' => __('Pending approval'), 'approved' => __('Approved')])
                ->default('pending')->required(),
            TextInput::make('source_file_path')->label(__('Source path'))->required(),
            CheckboxList::make('text_layer_fixes')->label(__('Text layer repairs'))->options([
                'mirrored_brackets' => __('Mirrored brackets'),
                'kaf_from_alef_madda' => __('Kaf from alef madda'),
            ])->columns(2),
            DatePicker::make('effective_from')->label(__('Effective date')),
            DatePicker::make('repealed_at')->label(__('Repeal date')),
            Select::make('supersedes_law_id')->label(__('Supersedes'))->options(fn (): array => Law::query()->where('kind', 'statute')->pluck('name', 'id')->all())->searchable(),
            Select::make('jurisdiction_values')->label(__('Jurisdictions'))->multiple()->options([
                Jurisdiction::FederalIraq->value => __('Federal Iraq'),
                Jurisdiction::KurdistanRegion->value => __('Kurdistan Region'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Law'))->searchable(),
            TextColumn::make('name_en')->label(__('English name'))->searchable()->toggleable(),
            TextColumn::make('number')->label(__('Number')),
            TextColumn::make('year')->label(__('Year')),
            TextColumn::make('category')->label(__('Category'))->formatStateUsing(fn ($state): string => LegalLibraryLabels::category($state instanceof \BackedEnum ? $state->value : (string) $state)),
            TextColumn::make('jurisdictions.jurisdiction')->label(__('Jurisdictions'))->badge()
                ->formatStateUsing(fn ($state): string => LegalLibraryLabels::jurisdiction($state instanceof \BackedEnum ? $state->value : (string) $state)),
            TextColumn::make('effective_from')->label(__('Effective'))->formatStateUsing(fn ($state): string => Ui::date($state)),
            TextColumn::make('repealed_at')->label(__('Repeal'))->formatStateUsing(fn ($state): string => Ui::date($state)),
            TextColumn::make('units_count')->label(__('Units'))->counts('units'),
            TextColumn::make('verified_count')->label(__('Verified'))->state(fn (Law $record): int => $record->units()->whereIn('status', ['verified_auto', 'verified_human', 'verified_text', 'verified_single'])->count()),
            TextColumn::make('mismatch_count')->label(__('Mismatch'))->state(fn (Law $record): int => $record->units()->where('status', 'mismatch')->count()),
            TextColumn::make('status_counts')->label(__('Statuses'))->state(fn (Law $record): string => $record->units()
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
                ->map(fn ($count, $status): string => LegalLibraryLabels::unitStatus($status).': '.Ui::number((int) $count))
                ->implode(__(', '))),
            TextColumn::make('latest_run')->label(__('Latest import'))->state(fn (Law $record): string => ($state = $record->importRuns()->latest('id')->value('state')) ? LegalLibraryLabels::runState($state) : '—'),
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
