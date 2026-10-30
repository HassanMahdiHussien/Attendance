<?php

namespace App\Filament\Admin\Resources;

use App\Enums\Jurisdiction;
use App\Filament\Admin\Resources\BranchResource\Pages;
use App\Models\Branch;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    public static function getModelLabel(): string
    {
        return __('Branch');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Branches');
    }

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Branch name'))->required(),
            TagsInput::make('alternative_names')->label(__('Alternative names')),
            TextInput::make('address')->label(__('Address')),
            Select::make('jurisdiction')->label(__('Jurisdiction'))->options([
                Jurisdiction::FederalIraq->value => __('Federal Iraq'),
                Jurisdiction::KurdistanRegion->value => __('Kurdistan Region'),
            ])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Branch')),
            TextColumn::make('aliases.name')->label(__('Alternative names'))->listWithLineBreaks(),
            TextColumn::make('jurisdiction')->label(__('Jurisdiction'))
                ->formatStateUsing(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                    'federal_iraq' => __('Federal Iraq'), 'kurdistan_region' => __('Kurdistan Region'),
                }),
            TextColumn::make('is_active')->label(__('Status'))->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            Action::make('deactivate')->label(__('Deactivate'))->color('danger')->requiresConfirmation()
                ->visible(fn (Branch $record): bool => $record->is_active && auth()->user()->can('update', $record))
                ->action(fn (Branch $record) => $record->update(['is_active' => false])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranchs::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'view' => Pages\ViewBranch::route('/{record}'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}
