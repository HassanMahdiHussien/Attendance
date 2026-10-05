<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\LawFirmPartnerResource\Pages;
use App\Models\Company;
use App\Models\LawFirmPartner;
use App\Services\PartnershipService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class LawFirmPartnerResource extends Resource
{
    protected static ?string $model = LawFirmPartner::class;

    protected static ?string $modelLabel = 'مكتب محاماة';

    protected static ?string $pluralModelLabel = 'مكاتب المحاماة';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('الاسم')->required()->maxLength(255),
            TextInput::make('contact_email')->label('البريد الإلكتروني')->email()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الاسم'),
            TextColumn::make('contact_email')->label('البريد الإلكتروني'),
        ])->recordActions([
            EditAction::make(),
            Action::make('attachCompany')->label('ربط شركة')->schema([
                Select::make('company_id')->label('الشركة')->options(fn (): array => Company::query()->pluck('name', 'id')->all())->searchable()->required(),
            ])->action(function (LawFirmPartner $record, array $data): void {
                $company = Company::findOrFail($data['company_id']);
                app(PartnershipService::class)->lawFirm($company, $record->id, true, auth()->user());
            }),
            Action::make('detachCompany')->label('إلغاء ربط شركة')->color('danger')->schema([
                Select::make('company_id')->label('الشركة')->options(fn (LawFirmPartner $record): array => Company::query()
                    ->whereIn('id', DB::table('company_law_firm_partner')->where('law_firm_partner_id', $record->id)->select('company_id'))
                    ->pluck('name', 'id')->all())->required(),
            ])->requiresConfirmation()->action(function (LawFirmPartner $record, array $data): void {
                $company = Company::findOrFail($data['company_id']);
                app(PartnershipService::class)->lawFirm($company, $record->id, false, auth()->user());
            }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLawFirmPartners::route('/'),
            'create' => Pages\CreateLawFirmPartner::route('/create'),
            'edit' => Pages\EditLawFirmPartner::route('/{record}/edit'),
        ];
    }
}
