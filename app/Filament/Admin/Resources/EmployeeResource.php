<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\EmployeeResource\Pages;
use App\Models\Branch;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    public static function getModelLabel(): string
    {
        return __('Employee');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Employees');
    }

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('employee_code')->label(__('Employee number'))->helperText(__('Generated automatically if left blank'))->scopedUnique(ignoreRecord: true),
            TextInput::make('name')->label(__('Name'))->required(),
            TextInput::make('name_en')->label(__('English name')),
            Select::make('gender')->label(__('Gender'))->options(['male' => __('Male'), 'female' => __('Female')])->required(),
            Select::make('branch_id')->label(__('Branch'))->options(fn (): array => Branch::query()->where('is_active', true)->pluck('name', 'id')->all())->required()->searchable(),
            TextInput::make('department')->label(__('Department'))->required(),
            TextInput::make('department_en')->label(__('English department')),
            TextInput::make('position')->label(__('Position'))->required(),
            TextInput::make('position_en')->label(__('English position')),
            DatePicker::make('hire_date')->label(__('Hire date'))->required(),
            TextInput::make('email')->label(__('Email address'))->email(),
            Checkbox::make('is_general_manager')->label(__('General Manager'))
                ->helperText(__('Only one active General Manager may be selected; clear the current one first.')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('employee_code')->label(__('Number')),
            TextColumn::make('name')->label(__('Employee')),
            TextColumn::make('branch.name')->label(__('Branch')),
            TextColumn::make('department')->label(__('Department')),
            TextColumn::make('is_active')->label(__('Status'))->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
        ])->recordActions([
            EditAction::make(),
            Action::make('deactivate')->label(__('Deactivate'))->color('danger')->requiresConfirmation()
                ->visible(fn (Employee $record): bool => $record->is_active && auth()->user()->can('update', $record))
                ->action(fn (Employee $record) => $record->update(['is_active' => false])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'import' => Pages\ImportEmployees::route('/import'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
