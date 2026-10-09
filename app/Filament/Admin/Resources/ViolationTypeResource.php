<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ViolationTypeResource\Pages;
use App\Models\ViolationType;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ViolationTypeResource extends Resource
{
    protected static ?string $model = ViolationType::class;

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('Violation type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Violation types');
    }

    public static function form(Schema $schema): Schema
    {
        $languages = Filament::getTenant()?->enabled_languages ?? auth()->user()?->company?->enabled_languages ?? ['ar', 'en'];
        $fields = [
            TextInput::make('names.ar')->label(__('Arabic name'))->required()->maxLength(255),
            TextInput::make('names.en')->label(__('English name'))->required()->maxLength(255),
        ];
        foreach (array_diff($languages, ['ar', 'en']) as $language) {
            $fields[] = TextInput::make('names.'.$language)->label(__('Name in :language', ['language' => strtoupper($language)]))->maxLength(255);
        }
        $fields[] = TextInput::make('sort_order')->label(__('Sort order'))->numeric()->default(0)->required();

        return $schema->components($fields);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('names.ar')->label(__('Arabic name')),
            TextColumn::make('names.en')->label(__('English name')),
            TextColumn::make('sort_order')->label(__('Sort order'))->sortable(),
            TextColumn::make('is_active')->label(__('Status'))->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
        ])->defaultSort('sort_order')->recordActions([
            EditAction::make(),
            Action::make('deactivate')->label(__('Deactivate'))->color('danger')->requiresConfirmation()
                ->visible(fn (ViolationType $record): bool => $record->is_active && auth()->user()->can('update', $record))
                ->action(fn (ViolationType $record) => $record->update(['is_active' => false])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListViolationTypes::route('/'),
            'create' => Pages\CreateViolationType::route('/create'),
            'edit' => Pages\EditViolationType::route('/{record}/edit'),
        ];
    }
}
