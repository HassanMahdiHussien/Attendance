<?php

namespace App\Filament\Admin\Resources;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Services\UserAccountService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function getModelLabel(): string
    {
        return __('Company user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Company users');
    }

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant, 404);

        $query = User::query()->inCompany($tenant);
        if (auth()->user()->primary_role === UserRole::HrStaff) {
            $query->whereIn('primary_role', [UserRole::Reporter->value, UserRole::DeptManager->value]);
        }

        return $query;
    }

    public static function allowedRoles(): array
    {
        $roles = [
            UserRole::Reporter->value => __('Reporter'),
            UserRole::DeptManager->value => __('Department manager'),
        ];
        if (auth()->user()->primary_role !== UserRole::HrStaff) {
            $roles = [
                UserRole::CompanyAdmin->value => __('Company administrator'),
                UserRole::HrStaff->value => __('HR employee'),
                UserRole::CommitteeMember->value => __('Committee member'),
                ...$roles,
            ];
        }

        return $roles;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required(),
            TextInput::make('email')->label(__('Email address'))->email()->required(),
            TextInput::make('password')->label(__('Password'))->password()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state)),
            Select::make('primary_role')->label(__('Role'))->options(fn (): array => self::allowedRoles())->required()->live(),
            Select::make('employee_id')->label(__('Linked employee'))->options(fn (): array => Employee::query()->pluck('name', 'id')->all())->searchable(),
            Select::make('branch_ids')->label(__('Branch scope'))->multiple()
                ->options(fn (): array => Branch::query()->pluck('name', 'id')->all())
                ->visible(fn ($get): bool => in_array($get('primary_role'), [UserRole::Reporter->value, UserRole::DeptManager->value], true)),
            CheckboxList::make('addons')->label(__('Additional permissions'))
                ->options(fn ($get): array => [
                    'review_internal_regulation' => __('Internal regulation review'),
                    ...($get('primary_role') === UserRole::HrStaff->value ? ['view_all_company_cases' => __('View all company cases')] : []),
                ])->visible(fn (): bool => auth()->user()->primary_role !== UserRole::HrStaff),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name')),
            TextColumn::make('email')->label(__('Email')),
            TextColumn::make('primary_role')->label(__('Role'))
                ->formatStateUsing(fn ($state): string => [
                    UserRole::CompanyAdmin->value => __('Company administrator'),
                    UserRole::HrStaff->value => __('HR employee'),
                    UserRole::CommitteeMember->value => __('Committee member'),
                    UserRole::Reporter->value => __('Reporter'),
                    UserRole::DeptManager->value => __('Department manager'),
                ][$state instanceof \BackedEnum ? $state->value : $state]),
            TextColumn::make('is_active')->label(__('Status'))->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
        ])->recordActions([
            EditAction::make(),
            Action::make('toggleActive')->label(fn (User $record): string => $record->is_active ? __('Deactivate') : __('Activate'))
                ->requiresConfirmation()
                ->action(function (User $record): void {
                    try {
                        app(UserAccountService::class)->update($record, ['is_active' => ! $record->is_active], auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title($exception->errors()['primary_role'][0] ?? $exception->getMessage())->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
