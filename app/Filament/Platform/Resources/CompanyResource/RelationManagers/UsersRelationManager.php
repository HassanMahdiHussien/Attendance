<?php

namespace App\Filament\Platform\Resources\CompanyResource\RelationManagers;

use App\Filament\Actions\ResetPasswordAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name')),
            TextColumn::make('email')->label(__('Email')),
            TextColumn::make('primary_role')->label(__('Role')),
            TextColumn::make('is_active')->label(__('Status'))
                ->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
        ])->recordActions([ResetPasswordAction::make()]);
    }
}
