<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CommitteeMandateResource\Pages;
use App\Filament\Admin\Resources\CommitteeMandateResource\RelationManagers\PoolMembersRelationManager;
use App\Models\CommitteeMandate;
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

    protected static ?string $modelLabel = 'أمر إداري';

    protected static ?string $pluralModelLabel = 'الأوامر الإدارية';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('order_number')->label('رقم الأمر')->required(),
            DatePicker::make('order_date')->label('تاريخ الأمر')->required(),
            DatePicker::make('effective_from')->label('ساري من')->required(),
            TextInput::make('source_file_path')->label('مسار نسخة الأمر'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('order_number')->label('رقم الأمر'),
            TextColumn::make('order_date')->label('تاريخ الأمر')->date(),
            TextColumn::make('effective_from')->label('ساري من')->date(),
            TextColumn::make('superseded_at')->label('نهاية السريان')->date(),
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
