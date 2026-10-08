<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CommitteeMandateResource\Pages;
use App\Filament\Admin\Resources\CommitteeMandateResource\RelationManagers\PoolMembersRelationManager;
use App\Models\CommitteeMandate;
use App\Support\Format\Ui;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommitteeMandateResource extends Resource
{
    protected static ?string $model = CommitteeMandate::class;

    public static function getModelLabel(): string
    {
        return __('Administrative order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Administrative orders');
    }

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('order_number')->label(__('Order number'))->required(),
            DatePicker::make('order_date')->label(__('Order date'))->required(),
            DatePicker::make('effective_from')->label(__('Effective from'))->required(),
            TextInput::make('source_file_path')->label(__('Order copy path')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_number')->label(__('Order number')),
            TextColumn::make('order_date')->label(__('Order date'))->formatStateUsing(fn ($state): string => Ui::date($state)),
            TextColumn::make('effective_from')->label(__('Effective from'))->formatStateUsing(fn ($state): string => Ui::date($state)),
            TextColumn::make('superseded_at')->label(__('Valid until'))->formatStateUsing(fn ($state): string => Ui::date($state)),
        ])->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [PoolMembersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMandates::route('/'),
            'create' => Pages\CreateMandate::route('/create'),
            'view' => Pages\ViewMandate::route('/{record}'),
        ];
    }
}
