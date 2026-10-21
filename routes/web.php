<?php

use App\Http\Controllers\HelpShotController;
use App\Http\Controllers\LocaleController;
use App\Livewire\Auth\Login;
use App\Livewire\Dashboard\HrDashboard;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Employees\EmployeePage;
use App\Livewire\Help\HelpArticlePage;
use App\Livewire\Help\HelpIndex;
use App\Livewire\Home;
use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

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
    Route::post('/logout', function (Request $request): RedirectResponse {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    })->name('logout');
    Route::get('/dashboard', HrDashboard::class)->name('dashboard.index');
    Route::get('/employees', EmployeeIndex::class)->name('employees.index');
    Route::get('/employees/{employee}', EmployeePage::class)->name('employees.show');
    Route::get('/home', Home::class)->name('workspace.home');
});
