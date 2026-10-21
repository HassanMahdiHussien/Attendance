<?php

namespace App\Filament\Platform\Resources;

use App\Enums\SubscriptionStatus;
use App\Filament\Platform\Resources\CompanyResource\Pages;
use App\Models\Company;
use App\Services\CompanyStatusService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    public static function getModelLabel(): string
    {
        return __('Company');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Companies');
    }

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Company name'))->required()->maxLength(255),
            Checkbox::make('kurdish_enabled')->label(__('Enable Kurdish (optional)')),
            Select::make('subscription_status')->label(__('Subscription status'))
                ->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $status) => [$status->value => match ($status) {
                    SubscriptionStatus::Trial => __('Trial'),
                    SubscriptionStatus::Active => __('Active'),
                    SubscriptionStatus::Suspended => __('Suspended'),
                    SubscriptionStatus::Cancelled => __('Cancelled'),
                }])->all())->required()->default(SubscriptionStatus::Trial->value),
            TextInput::make('first_hr_user_name')->label(__('First HR user name'))->required(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('first_hr_user_email')->label(__('First HR user email'))->email()->required(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('first_hr_user_password')->label(__('First HR user password'))->password()->required(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Company')),
            TextColumn::make('subscription_status')->label(__('Subscription'))
                ->formatStateUsing(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                    'trial' => __('Trial'), 'active' => __('Active'), 'suspended' => __('Suspended'), 'cancelled' => __('Cancelled'),
                }),
            TextColumn::make('is_active')->label(__('Account'))->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
        ])->recordActions([
            EditAction::make(),
            Action::make('toggleActive')->label(fn (Company $record): string => $record->is_active ? __('Deactivate') : __('Activate'))
                ->color(fn (Company $record): string => $record->is_active ? 'danger' : 'success')
                ->requiresConfirmation()
                ->action(fn (Company $record) => app(CompanyStatusService::class)->setActive($record, ! $record->is_active, auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
