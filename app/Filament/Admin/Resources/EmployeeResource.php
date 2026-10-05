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

    protected static ?string $modelLabel = 'موظف';

    protected static ?string $pluralModelLabel = 'الموظفون';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('employee_code')->label('رقم الموظف')->helperText('يُنشأ تلقائياً إذا تُرك فارغاً')->scopedUnique(ignoreRecord: true),
            TextInput::make('name')->label('الاسم')->required(),
            Select::make('branch_id')->label('الفرع')->options(fn (): array => Branch::query()->where('is_active', true)->pluck('name', 'id')->all())->required()->searchable(),
            TextInput::make('department')->label('القسم')->required(),
            TextInput::make('position')->label('المنصب')->required(),
            DatePicker::make('hire_date')->label('تاريخ التعيين')->required(),
            TextInput::make('email')->label('البريد الإلكتروني')->email(),
            Checkbox::make('is_general_manager')->label('المدير العام')
                ->helperText('يمكن تحديد مدير عام نشط واحد فقط؛ أزل العلامة عن المدير الحالي أولاً.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('employee_code')->label('الرقم'),
            TextColumn::make('name')->label('الموظف'),
            TextColumn::make('branch.name')->label('الفرع'),
            TextColumn::make('department')->label('القسم'),
            TextColumn::make('is_active')->label('الحالة')->formatStateUsing(fn (bool $state): string => $state ? 'نشط' : 'معطل'),
        ])->recordActions([
            EditAction::make(),
            Action::make('deactivate')->label('تعطيل')->color('danger')->requiresConfirmation()
                ->visible(fn (Employee $record): bool => $record->is_active && auth()->user()->can('update', $record))
                ->action(fn (Employee $record) => $record->update(['is_active' => false])),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
