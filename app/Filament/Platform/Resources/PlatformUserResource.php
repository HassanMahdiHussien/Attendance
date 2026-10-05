<?php

namespace App\Filament\Platform\Resources;

use App\Enums\UserRole;
use App\Filament\Platform\Resources\PlatformUserResource\Pages;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
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

    protected static ?string $modelLabel = 'مستخدم المنصة';

    protected static ?string $pluralModelLabel = 'مستخدمو المنصة';

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return User::query()->whereIn('primary_role', [
            UserRole::PlatformAdmin->value,
            UserRole::ExternalLegalReviewer->value,
            UserRole::HrPartnerStaff->value,
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('الاسم')->required(),
            TextInput::make('email')->label('البريد الإلكتروني')->email()->required(),
            TextInput::make('password')->label('كلمة المرور')->password()->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state)),
            Select::make('primary_role')->label('الدور')->options([
                UserRole::PlatformAdmin->value => 'مدير المنصة',
                UserRole::ExternalLegalReviewer->value => 'مراجع قانوني خارجي',
                UserRole::HrPartnerStaff->value => 'موظف شريك الموارد البشرية',
            ])->required()->live(),
            Select::make('law_firm_partner_id')->label('مكتب المحاماة')->options(fn (): array => LawFirmPartner::query()->pluck('name', 'id')->all())
                ->visible(fn ($get): bool => $get('primary_role') === UserRole::ExternalLegalReviewer->value)
                ->required(fn ($get): bool => $get('primary_role') === UserRole::ExternalLegalReviewer->value),
            Select::make('hr_partner_id')->label('شريك الموارد البشرية')->options(fn (): array => HrPartner::query()->pluck('name', 'id')->all())
                ->visible(fn ($get): bool => $get('primary_role') === UserRole::HrPartnerStaff->value)
                ->required(fn ($get): bool => $get('primary_role') === UserRole::HrPartnerStaff->value),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الاسم'),
            TextColumn::make('email')->label('البريد'),
            TextColumn::make('primary_role')->label('الدور')
                ->formatStateUsing(fn ($state): string => [
                    UserRole::PlatformAdmin->value => 'مدير المنصة',
                    UserRole::ExternalLegalReviewer->value => 'مراجع قانوني خارجي',
                    UserRole::HrPartnerStaff->value => 'موظف شريك الموارد البشرية',
                ][$state instanceof \BackedEnum ? $state->value : $state]),
            TextColumn::make('is_active')->label('الحالة')->formatStateUsing(fn (bool $state): string => $state ? 'نشط' : 'معطل'),
        ])->recordActions([
            EditAction::make(),
            Action::make('toggleActive')->label(fn (User $record): string => $record->is_active ? 'تعطيل' : 'تفعيل')
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
