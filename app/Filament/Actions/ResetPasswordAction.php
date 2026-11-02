<?php

namespace App\Filament\Actions;

use App\Models\User;
use App\Services\UserAccountService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\Rules\Password;

class ResetPasswordAction
{
    public static function make(): Action
    {
        return Action::make('resetPassword')
            ->label(__('messages.password.reset'))
            ->visible(fn (User $record): bool => auth()->user()->can('update', $record) && ! auth()->user()->is($record))
            ->schema([
                TextInput::make('temporary_password')->label(__('messages.password.temporary'))
                    ->password()->revealable()->required()->rules([Password::defaults()])
                    ->suffixAction(Action::make('generate')->label(__('messages.password.generate'))
                        ->action(fn (Set $set) => $set('temporary_password', self::generateTemporaryPassword()))),
                TextInput::make('temporary_password_confirmation')->label(__('messages.password.confirm'))
                    ->password()->revealable()->required()->same('temporary_password'),
            ])
            ->action(function (User $record, array $data): void {
                app(UserAccountService::class)->resetPassword($record, $data['temporary_password'], auth()->user());
                Notification::make()->success()->title(__('messages.password.reset_done'))->send();
            });
    }

    private static function generateTemporaryPassword(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 12; $i++) {
            $password .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $password;
    }
}
