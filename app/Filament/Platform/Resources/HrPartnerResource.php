<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\HrPartnerResource\Pages;
use App\Models\Company;
use App\Models\HrPartner;
use App\Services\PartnershipService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class HrPartnerResource extends Resource
{
    protected static ?string $model = HrPartner::class;

    protected static ?string $modelLabel = 'شريك موارد بشرية';

    protected static ?string $pluralModelLabel = 'شركاء الموارد البشرية';

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
                Checkbox::make('full_case_access')->label('الوصول الكامل للقضايا'),
            ])->action(function (HrPartner $record, array $data): void {
                $company = Company::findOrFail($data['company_id']);
                app(PartnershipService::class)->hrPartner($company, $record->id, (bool) ($data['full_case_access'] ?? false), auth()->user());
            }),
            Action::make('detachCompany')->label('إلغاء ربط شركة')->color('danger')->schema([
                Select::make('company_id')->label('الشركة')->options(fn (HrPartner $record): array => Company::query()
                    ->whereIn('id', DB::table('company_hr_partner')->where('hr_partner_id', $record->id)->select('company_id'))
                    ->pluck('name', 'id')->all())->required(),
            ])->requiresConfirmation()->action(function (HrPartner $record, array $data): void {
                $company = Company::findOrFail($data['company_id']);
                app(PartnershipService::class)->hrPartner($company, $record->id, null, auth()->user());
            }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHrPartners::route('/'),
            'create' => Pages\CreateHrPartner::route('/create'),
            'edit' => Pages\EditHrPartner::route('/{record}/edit'),
        ];
    }
}
