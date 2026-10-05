<?php

namespace App\Filament\Admin\Resources;

use App\Enums\Jurisdiction;
use App\Filament\Admin\Resources\BranchResource\Pages;
use App\Models\Branch;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $modelLabel = 'فرع';

    protected static ?string $pluralModelLabel = 'الفروع';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم الفرع')->required(),
            TextInput::make('address')->label('العنوان'),
            Select::make('jurisdiction')->label('الولاية القضائية')->options([
                Jurisdiction::FederalIraq->value => 'العراق الاتحادي',
                Jurisdiction::KurdistanRegion->value => 'إقليم كردستان',
            ])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الفرع'),
            TextColumn::make('jurisdiction')->label('الولاية القضائية')
                ->formatStateUsing(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                    'federal_iraq' => 'العراق الاتحادي', 'kurdistan_region' => 'إقليم كردستان',
                }),
            TextColumn::make('is_active')->label('الحالة')->formatStateUsing(fn (bool $state): string => $state ? 'نشط' : 'معطل'),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            Action::make('deactivate')->label('تعطيل')->color('danger')->requiresConfirmation()
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
