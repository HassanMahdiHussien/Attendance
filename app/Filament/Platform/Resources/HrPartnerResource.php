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

    public static function getModelLabel(): string
    {
        return __('HR partner');
    }

    public static function getPluralModelLabel(): string
    {
        return __('HR partners');
    }

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
            TextInput::make('contact_email')->label(__('Email address'))->email()->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('Name')),
            TextColumn::make('contact_email')->label(__('Email address')),
        ])->recordActions([
            EditAction::make(),
            Action::make('attachCompany')->label(__('Link company'))->schema([
                Select::make('company_id')->label(__('Company'))->options(fn (): array => Company::query()->pluck('name', 'id')->all())->searchable()->required(),
                Checkbox::make('full_case_access')->label(__('Full case access')),
            ])->action(function (HrPartner $record, array $data): void {
                $company = Company::findOrFail($data['company_id']);
                app(PartnershipService::class)->hrPartner($company, $record->id, (bool) ($data['full_case_access'] ?? false), auth()->user());
            }),
            Action::make('detachCompany')->label(__('Unlink company'))->color('danger')->schema([
                Select::make('company_id')->label(__('Company'))->options(fn (HrPartner $record): array => Company::query()
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
