<?php

namespace App\Filament\Admin\Resources;

use App\Enums\EmployeeExitStatus;
use App\Filament\Admin\Pages\Attendance\AttendanceIssues;
use App\Filament\Admin\Resources\EmployeeResource\Pages;
use App\Models\Attendance\DataIssue;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Employees\ExitReview;
use App\Services\Employees\PatternRule;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
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

    // The workspace already has an "Employees" item (search and profiles); this one manages the records.
    public static function getNavigationLabel(): string
    {
        return __('Employee records');
    }

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('employee_code')->label(__('Employee number'))->helperText(__('Generated automatically if left blank'))->scopedUnique(ignoreRecord: true)
                ->disabled(fn (?Employee $record): bool => $record !== null)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('name')->label(__('Name'))->required(),
            TextInput::make('name_en')->label(__('English name')),
            Select::make('gender')->label(__('Gender'))->options(['male' => __('Male'), 'female' => __('Female')])->required(),
            Select::make('branch_id')->label(__('Branch'))->options(fn (): array => Branch::query()->where('is_active', true)->pluck('name', 'id')->all())->required()->searchable(),
            TextInput::make('department')->label(__('Department')),
            TextInput::make('department_en')->label(__('English department')),
            TextInput::make('position')->label(__('Position')),
            TextInput::make('position_en')->label(__('English position')),
            Select::make('staff_category')->label(__('messages.employee_history.fields.staff_category'))->options([
                'B' => __('messages.employee.staff_categories.B'), 'W' => __('messages.employee.staff_categories.W'),
            ]),
            TextInput::make('legal_entity')->label(__('messages.employee_history.fields.legal_entity')),
            DatePicker::make('hire_date')->label(__('Hire date'))->required(),
            TextInput::make('email')->label(__('Email address'))->email()->extraInputAttributes(['dir' => 'ltr']),
            Select::make('work_pattern')->label(__('Work pattern'))
                ->helperText(fn (?Employee $record): ?string => $record !== null && app(PatternRule::class)->source($record) === 'rule'
                    ? __('messages.employee_history.set_by_rule') : null)
                ->hintAction(Action::make('patternReview')->label(__('messages.employee_history.pattern_review_link'))
                    ->url(fn (?Employee $record): ?string => $record === null ? null : AttendanceIssues::getUrl(panel: 'admin', tenant: $record->company)
                        .'?'.http_build_query(['type' => 'pattern_review', 'subjectKey' => 'employee:'.$record->id]))
                    ->visible(fn (?Employee $record): bool => $record !== null && DataIssue::query()
                        ->where('company_id', $record->company_id)
                        ->where('open_key', 'pattern_review|employee|employee:'.$record->id)->exists()))->options([
                            'office' => __('Office'), 'shift' => __('Shift'), 'security' => __('Security'),
                        ]),
            CheckboxList::make('rest_days')->label(__('Rest days'))->options([
                1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'),
                5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday'),
            ])->columns(4),
            TimePicker::make('schedule_start')->label(__('Schedule start'))->seconds(false)->extraInputAttributes(['dir' => 'ltr']),
            TimePicker::make('schedule_end')->label(__('Schedule end'))->seconds(false)->extraInputAttributes(['dir' => 'ltr']),
            Select::make('manager_employee_id')->label(__('Manager'))->options(fn (?Employee $record): array => Employee::query()
                ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                ->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            TextInput::make('manager_name')->label(__('Reported manager name')),
            TextInput::make('fallback_notification_email')->label(__('Fallback notification email'))->email()->extraInputAttributes(['dir' => 'ltr']),
            DatePicker::make('effective_from')->label(__('Effective from'))->default(today())->required()->dehydrated(),
            TextInput::make('change_reason')->label(__('Reason for correction')),
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
            Action::make('startExitReview')->label(__('Start exit review'))->color('danger')->requiresConfirmation()
                ->visible(fn (Employee $record): bool => $record->is_active && $record->exit_status === EmployeeExitStatus::None
                    && auth()->user()->can('update', $record))
                ->action(fn (Employee $record) => app(ExitReview::class)->start($record, auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'import' => Pages\ImportEmployees::route('/import'),
            'exit-review' => Pages\ExitReviewEmployees::route('/exit-review'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
