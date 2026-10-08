<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\DocumentOutputController;
use App\Http\Controllers\LegalPageImageController;
use App\Http\Controllers\ReportExportDownloadController;
use App\Http\Middleware\SetTenantContext;
use App\Livewire\Auth\Login;
use App\Livewire\Cases\Board;
use App\Livewire\Cases\ConfirmDocument;
use App\Livewire\Cases\CreateCase;
use App\Livewire\Cases\EditDecisionLetter;
use App\Livewire\Cases\EditDocument;
use App\Livewire\Cases\EditRecommendations;
use App\Livewire\Cases\MyCases;
use App\Livewire\Cases\PendingApprovals;
use App\Livewire\Cases\RecordAppeal;
use App\Livewire\Cases\RecordSession;
use App\Livewire\Cases\ReviewApproval;
use App\Livewire\Cases\ReviewConsultation;
use App\Livewire\Cases\ShowCase;
use App\Livewire\Cases\SignedScan;
use App\Livewire\Dashboard\CaseDrilldown;
use App\Livewire\Dashboard\HrDashboard;
use App\Livewire\Dashboard\PartnerCases;
use App\Livewire\Employees\EmployeeIndex;
use App\Livewire\Employees\EmployeePage;
use App\Livewire\Home;
use App\Livewire\Reports\Inbox;
use App\Livewire\Reports\MyReports;
use App\Livewire\Reports\SubmitReport;
use App\Livewire\Statistics\Reports as StatisticsReports;
use App\Models\Company;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->get('/login', Login::class)->name('login');

Route::middleware('auth')->group(function (): void {
    Route::get('/admin/{company}/employees/import/template', function (Company $company) {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($company);
        Gate::authorize('importEmployees', $company);

        return response("\xEF\xBB\xBFالاسم,رقم الموظف,القسم,المنصب,تاريخ التعيين,الفرع,البريد,الجنس\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employees-template.csv"',
        ]);
    })->name('employees.import.template');
    Route::get('/legal-library/runs/{run}/pages/{page}', LegalPageImageController::class)
        ->middleware(SetTenantContext::class)->name('legal-library.pages.show');
    Route::post('/logout', function (Request $request): RedirectResponse {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    })->name('logout');
    Route::get('/cases', Board::class)->name('cases.index');
    Route::get('/dashboard', HrDashboard::class)->name('dashboard.index');
    Route::get('/dashboard/cases', CaseDrilldown::class)->name('dashboard.cases');
    Route::get('/employees', EmployeeIndex::class)->name('employees.index');
    Route::get('/employees/{employee}', EmployeePage::class)->name('employees.show');
    Route::get('/statistics', StatisticsReports::class)->name('statistics.index');
    Route::get('/statistics/exports/{export}', ReportExportDownloadController::class)->name('statistics.exports.download');
    Route::get('/cases/new', CreateCase::class)->name('cases.create');
    Route::get('/cases/{case}', ShowCase::class)->name('cases.show');
    Route::get('/cases/{case}/session', RecordSession::class)->name('cases.session');
    Route::get('/cases/{case}/recommendations', EditRecommendations::class)->name('cases.recommendations');
    Route::get('/cases/{case}/recommendations/{document}', EditRecommendations::class)->name('cases.recommendations.edit');
    Route::get('/cases/{case}/signed-scan', SignedScan::class)->name('cases.signed-scan');
    Route::get('/cases/{case}/decision', EditDecisionLetter::class)->name('cases.decision');
    Route::get('/cases/{case}/appeal', RecordAppeal::class)->name('cases.appeal');
    Route::get('/cases/{case}/consultation', ReviewConsultation::class)->name('cases.consultation');
    Route::get('/committee', PendingApprovals::class)->name('committee.pending');
    Route::get('/committee/approvals/{document}', ReviewApproval::class)->name('committee.review');
    Route::get('/cases/{case}/documents/{document}/edit', EditDocument::class)->name('cases.documents.edit');
    Route::get('/cases/{case}/documents/{document}/confirm', ConfirmDocument::class)->name('cases.documents.confirm');
    Route::get('/reports', MyReports::class)->name('reports.mine');
    Route::get('/reports/new', SubmitReport::class)->name('reports.create');
    Route::get('/reports/inbox', Inbox::class)->name('reports.inbox');
    Route::get('/my-cases', MyCases::class)->name('cases.mine');
    Route::get('/home', Home::class)->name('workspace.home');
    Route::get('/home/cases/{company}', PartnerCases::class)->name('partner.cases');
    Route::get('/attachments/{attachment}', AttachmentDownloadController::class)->name('attachments.download');
    Route::get('/documents/{document}/preview', [DocumentOutputController::class, 'preview'])->name('documents.preview');
    Route::get('/documents/{document}/pdf', [DocumentOutputController::class, 'pdf'])->name('documents.pdf');
});
