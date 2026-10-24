<?php

use App\Http\Controllers\HelpShotController;
use App\Http\Controllers\LocaleController;
use App\Livewire\Attendance\DashboardEmployees;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard\HrDashboard;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Employees\EmployeePage;
use App\Livewire\Help\HelpArticlePage;
use App\Livewire\Help\HelpIndex;
use App\Livewire\Home;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\HolidaySource;
use App\Models\Attendance\ImportBatch;
use App\Models\Company;
use App\Models\EmployeeImportBatch;
use App\Models\Leave\ImportBatch as LeaveImportBatch;
use App\Services\AuditLogger;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('workspace.home');
    }

    return view('welcome');
});

Route::middleware('guest')->get('/login', Login::class)->name('login');
// One sign-in page for every role: the admin panels have no login of their own (old bookmarks still work).
Route::redirect('/admin/login', '/login');
Route::redirect('/platform/login', '/login');
Route::post('/locale', LocaleController::class)->name('locale.update');

Route::middleware('auth')->group(function (): void {
    Route::get('/help', HelpIndex::class)->name('help.index');
    Route::get('/help/shots/{locale}/{name}', HelpShotController::class)->name('help.shot');
    Route::get('/help/{section}/{slug}', HelpArticlePage::class)->name('help.article');
    Route::get('/admin/{company}/employees/import/template', function (Company $company) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        Gate::authorize('importEmployees', $company);

        return response("\xEF\xBB\xBFالاسم,رقم الموظف,القسم,المنصب,تاريخ التعيين,الفرع,البريد,الجنس\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employees-template.csv"',
        ]);
    })->name('employees.import.template');
    Route::get('/admin/{company}/employee-imports/{batch}/file', function (Company $company, int $batch) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        $record = EmployeeImportBatch::query()->where('company_id', $company->id)->findOrFail($batch);
        Gate::authorize('viewOriginal', $record);
        abort_unless(Storage::disk('local')->exists($record->file_path), 404);
        app(AuditLogger::class)->log('employee.import.file.downloaded', $record, ['batch_id' => $record->id]);

        return Storage::disk('local')->download($record->file_path, $record->file_name);
    })->name('employees.import.file');
    Route::get('/admin/{company}/attendance-imports/{attendanceBatch}/file', function (Company $company, int $attendanceBatch) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // A foreign tenant's URL must not reveal whether a batch exists.
        abort_unless(auth()->user()->canAccessTenant($company), 404);
        Filament::setTenant($company);
        $record = ImportBatch::query()->where('company_id', $company->id)->findOrFail($attendanceBatch);
        Gate::authorize('viewOriginal', $record);
        abort_unless(Storage::disk('local')->exists($record->file_path), 404);
        app(AuditLogger::class)->log('attendance.import.file.downloaded', $record, ['batch_id' => $record->id]);

        return Storage::disk('local')->download($record->file_path, $record->file_name);
    })->name('attendance.import.file');
    Route::get('/admin/{company}/leave-imports/{leaveBatch}/file', function (Company $company, int $leaveBatch) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // A foreign tenant's URL must not reveal whether a batch exists.
        abort_unless(auth()->user()->canAccessTenant($company), 404);
        Filament::setTenant($company);
        $record = LeaveImportBatch::query()->where('company_id', $company->id)->findOrFail($leaveBatch);
        Gate::authorize('viewOriginal', $record);
        abort_unless(Storage::disk('local')->exists($record->file_path), 404);
        app(AuditLogger::class)->log('leave.import.file.downloaded', $record, ['batch_id' => $record->id]);

        return Storage::disk('local')->download($record->file_path, $record->file_name);
    })->name('leave.import.file');
    Route::get('/admin/{company}/attendance-exports/{export}/file', function (Company $company, int $export) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(auth()->user()->canAccessTenant($company), 404);
        Filament::setTenant($company);
        $record = AttendanceExport::query()->where('company_id', $company->id)->findOrFail($export);
        Gate::authorize('view', $record);
        abort_unless($record->file_path !== null && Storage::disk('local')->exists($record->file_path), 404);
        app(AuditLogger::class)->log('attendance.export.downloaded', $record, ['export_id' => $record->id]);

        return Storage::disk('local')->download($record->file_path, $record->file_name);
    })->name('attendance.exports.file');
    Route::get('/admin/{company}/holidays/sources/{source}/original', function (Company $company, int $source) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(auth()->user()->canAccessTenant($company), 404);
        Filament::setTenant($company);
        Gate::authorize('update', $company);
        $record = HolidaySource::query()->where('company_id', $company->id)->findOrFail($source);
        abort_unless(Storage::disk('local')->exists($record->file_path), 404);
        app(AuditLogger::class)->log('attendance.holiday_source.original.downloaded', $record, ['source_id' => $record->id]);

        return Storage::disk('local')->download($record->file_path, $record->file_name);
    })->name('attendance.holidays.original');
    Route::get('/admin/{company}/holidays/sources/{source}/parts/{part}', function (Company $company, int $source, int $part) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(auth()->user()->canAccessTenant($company), 404);
        Filament::setTenant($company);
        Gate::authorize('update', $company);
        $record = HolidaySource::query()->where('company_id', $company->id)->findOrFail($source);
        $entry = $record->parts[$part] ?? null;
        abort_unless(is_array($entry) && in_array($entry['kind'] ?? null, ['text', 'image', 'page', 'pdf'], true)
            && isset($entry['path']) && str_starts_with($entry['path'], 'attendance/holidays/'.$company->id.'/'.$record->id.'/parts/')
            && ! str_contains($entry['path'], '..')
            && Storage::disk('local')->exists($entry['path']), 404);
        app(AuditLogger::class)->log('attendance.holiday_source.part.viewed', $record,
            ['source_id' => $record->id, 'part' => $part]);
        $mime = match ($entry['kind']) {
            'text' => 'text/plain; charset=UTF-8', 'pdf' => 'application/pdf',
            default => strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg',
        };

        return response(Storage::disk('local')->get($entry['path']), 200, [
            'Content-Type' => $mime, 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff',
        ]);
    })->name('attendance.holidays.part');
    Route::post('/logout', function (Request $request): RedirectResponse {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    })->name('logout');
    Route::get('/dashboard', HrDashboard::class)->name('dashboard.index');
    Route::get('/dashboard/employees', DashboardEmployees::class)->name('dashboard.employees');
    Route::get('/dashboard/company/{company}', HrDashboard::class)->name('dashboard.company');
    Route::get('/dashboard/company/{company}/employees', DashboardEmployees::class)->name('dashboard.company.employees');
    Route::get('/employees', EmployeeIndex::class)->name('employees.index');
    Route::get('/employees/{employee}', EmployeePage::class)->name('employees.show');
    Route::get('/home', Home::class)->name('workspace.home');
});
