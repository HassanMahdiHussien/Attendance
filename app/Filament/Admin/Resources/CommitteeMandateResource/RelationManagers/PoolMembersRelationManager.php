<?php

namespace App\Filament\Admin\Resources\CommitteeMandateResource\RelationManagers;

use App\Enums\PoolRole;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CommitteePoolMember;
use App\Models\Employee;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PoolMembersRelationManager extends RelationManager
{
    protected static string $relationship = 'poolMembers';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role_type')->label(__('Member role'))->options([
                PoolRole::Legal->value => __('Legal'),
                PoolRole::Hr->value => __('HR'),
                PoolRole::LaborUnion->value => __('Labor union'),
            ])->required(),
            Select::make('branch_id')->label(__('Branch (blank = entire company)'))
                ->options(fn (): array => Branch::query()->pluck('name', 'id')->all()),
            TextInput::make('person_name')->label(__('Name'))->required(),
            TextInput::make('person_name_en')->label(__('English name')),
            Select::make('employee_id')->label(__('Linked employee'))
                ->options(fn (): array => Employee::query()->pluck('name', 'id')->all())->searchable(),
            Select::make('user_id')->label(__('Committee member login'))
                ->options(fn (): array => User::query()->inCompany($this->ownerRecord->company_id)
                    ->where('primary_role', UserRole::CommitteeMember->value)->pluck('name', 'id')->all())->searchable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('person_name')->label(__('Name')),
            TextColumn::make('role_type')->label(__('Role'))
                ->formatStateUsing(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                    'legal' => __('Legal'), 'hr' => __('HR'), 'labor_union' => __('Labor union'),
                }),
            TextColumn::make('branch.name')->label(__('Branch')),
        ])->headerActions([
            CreateAction::make()->using(function (array $data): CommitteePoolMember {
                abort_unless(auth()->user()->can('create', CommitteePoolMember::class), 403);

                return $this->ownerRecord->poolMembers()->create($data + ['company_id' => $this->ownerRecord->company_id]);
            }),
        ])->recordActions([EditAction::make()]);
    }
}
