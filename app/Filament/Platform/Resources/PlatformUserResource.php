<?php

namespace App\Filament\Platform\Resources;

use App\Enums\UserRole;
use App\Filament\Platform\Resources\PlatformUserResource\Pages;
use App\Models\User;
use App\Services\UserAccountService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlatformUserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function getModelLabel(): string
    {
        return __('Platform user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Platform users');
    }

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return User::query()->whereIn('primary_role', [
            UserRole::PlatformAdmin->value,
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required(),
            TextInput::make('email')->label(__('Email address'))->email()->required(),
            TextInput::make('password')->label(__('Password'))->password()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state)),
            Select::make('primary_role')->label(__('Role'))->options([
                UserRole::PlatformAdmin->value => __('Platform administrator'),
            ])->required()->live(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name')),
            TextColumn::make('email')->label(__('Email')),
            TextColumn::make('primary_role')->label(__('Role'))
                ->formatStateUsing(fn ($state): string => [
                    UserRole::PlatformAdmin->value => __('Platform administrator'),
                ][$state instanceof \BackedEnum ? $state->value : $state]),
            TextColumn::make('is_active')->label(__('Status'))->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
        ])->recordActions([
            EditAction::make(),
            Action::make('toggleActive')->label(fn (User $record): string => $record->is_active ? __('Deactivate') : __('Activate'))
                ->requiresConfirmation()->action(fn (User $record) => app(UserAccountService::class)->update($record, ['is_active' => ! $record->is_active], auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlatformUsers::route('/'),
            'create' => Pages\CreatePlatformUser::route('/create'),
            'edit' => Pages\EditPlatformUser::route('/{record}/edit'),
        ];
    }
}
