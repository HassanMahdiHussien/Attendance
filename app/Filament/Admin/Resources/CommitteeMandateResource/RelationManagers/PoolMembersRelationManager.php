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
            Select::make('role_type')->label('صفة العضو')->options([
                PoolRole::Legal->value => 'قانوني',
                PoolRole::Hr->value => 'موارد بشرية',
                PoolRole::LaborUnion->value => 'نقابة العمال',
            ])->required(),
            Select::make('branch_id')->label('الفرع (فارغ = الشركة كلها)')
                ->options(fn (): array => Branch::query()->pluck('name', 'id')->all()),
            TextInput::make('person_name')->label('الاسم')->required(),
            TextInput::make('person_name_en')->label('الاسم بالإنجليزية'),
            Select::make('employee_id')->label('الموظف المرتبط')
                ->options(fn (): array => Employee::query()->pluck('name', 'id')->all())->searchable(),
            Select::make('user_id')->label('دخول عضو اللجنة')
                ->options(fn (): array => User::query()->inCompany($this->ownerRecord->company_id)
                    ->where('primary_role', UserRole::CommitteeMember->value)->pluck('name', 'id')->all())->searchable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('person_name')->label('الاسم'),
            TextColumn::make('role_type')->label('الصفة')
                ->formatStateUsing(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                    'legal' => 'قانوني', 'hr' => 'موارد بشرية', 'labor_union' => 'نقابة العمال',
                }),
            TextColumn::make('branch.name')->label('الفرع'),
        ])->headerActions([
            CreateAction::make()->using(function (array $data): CommitteePoolMember {
                abort_unless(auth()->user()->can('create', CommitteePoolMember::class), 403);

                return $this->ownerRecord->poolMembers()->create($data + ['company_id' => $this->ownerRecord->company_id]);
            }),
        ])->recordActions([EditAction::make()]);
    }
}
