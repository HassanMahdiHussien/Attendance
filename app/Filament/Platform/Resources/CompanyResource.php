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

    protected static ?string $modelLabel = 'شركة';

    protected static ?string $pluralModelLabel = 'الشركات';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم الشركة')->required()->maxLength(255),
            TextInput::make('letterhead_logo_path')->label('مسار الشعار'),
            TextInput::make('letterhead_address')->label('عنوان الترويسة'),
            TextInput::make('letterhead_phone')->label('هاتف الترويسة'),
            TextInput::make('letterhead_email')->label('بريد الترويسة')->email(),
            Checkbox::make('kurdish_enabled')->label('إضافة الكردية (اختياري)'),
            Checkbox::make('requires_second_review')->label('مراجعة ثانية قبل الإصدار'),
            Select::make('subscription_status')->label('حالة الاشتراك')
                ->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $status) => [$status->value => match ($status) {
                    SubscriptionStatus::Trial => 'تجريبي',
                    SubscriptionStatus::Active => 'نشط',
                    SubscriptionStatus::Suspended => 'معلق',
                    SubscriptionStatus::Cancelled => 'ملغى',
                }])->all())->required()->default(SubscriptionStatus::Trial->value),
            TextInput::make('first_admin_name')->label('اسم مدير الشركة الأول')->required(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('first_admin_email')->label('بريد مدير الشركة الأول')->email()->required(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('first_admin_password')->label('كلمة مرور مدير الشركة الأول')->password()->required(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الشركة'),
            TextColumn::make('subscription_status')->label('الاشتراك')
                ->formatStateUsing(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                    'trial' => 'تجريبي', 'active' => 'نشط', 'suspended' => 'معلق', 'cancelled' => 'ملغى',
                }),
            TextColumn::make('is_active')->label('الحساب')->formatStateUsing(fn (bool $state): string => $state ? 'نشط' : 'معطل'),
        ])->recordActions([
            EditAction::make(),
            Action::make('toggleActive')->label(fn (Company $record): string => $record->is_active ? 'تعطيل' : 'تفعيل')
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
