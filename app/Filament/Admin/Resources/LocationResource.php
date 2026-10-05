<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\LocationResource\Pages;
use App\Models\Branch;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $modelLabel = 'موقع';

    protected static ?string $pluralModelLabel = 'المواقع';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم الموقع')->required(),
            Select::make('branch_id')->label('الفرع')->options(fn (): array => Branch::query()->where('is_active', true)->pluck('name', 'id')->all())->required()->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الموقع'),
            TextColumn::make('branch.name')->label('الفرع'),
            TextColumn::make('is_active')->label('الحالة')->formatStateUsing(fn (bool $state): string => $state ? 'نشط' : 'معطل'),
        ])->recordActions([
            EditAction::make(),
            Action::make('deactivate')->label('تعطيل')->color('danger')->requiresConfirmation()
                ->visible(fn (Location $record): bool => $record->is_active && auth()->user()->can('update', $record))
                ->action(fn (Location $record) => $record->update(['is_active' => false])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}
