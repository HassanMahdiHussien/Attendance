<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Controllers\DocumentOutputController;
use App\Livewire\Auth\Login;
use App\Livewire\Cases\Board;
use App\Livewire\Cases\CreateCase;
use App\Livewire\Cases\MyCases;
use App\Livewire\Cases\ShowCase;
use App\Livewire\Home;
use App\Livewire\Reports\Inbox;
use App\Livewire\Reports\MyReports;
use App\Livewire\Reports\SubmitReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->get('/login', Login::class)->name('login');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', function (Request $request): RedirectResponse {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    })->name('logout');
    Route::get('/cases', Board::class)->name('cases.index');
    Route::get('/cases/new', CreateCase::class)->name('cases.create');
    Route::get('/cases/{case}', ShowCase::class)->name('cases.show');
    Route::get('/reports', MyReports::class)->name('reports.mine');
    Route::get('/reports/new', SubmitReport::class)->name('reports.create');
    Route::get('/reports/inbox', Inbox::class)->name('reports.inbox');
    Route::get('/my-cases', MyCases::class)->name('cases.mine');
    Route::get('/home', Home::class)->name('workspace.home');
    Route::get('/attachments/{attachment}', AttachmentDownloadController::class)->name('attachments.download');
    Route::get('/documents/{document}/preview', [DocumentOutputController::class, 'preview'])->name('documents.preview');
    Route::get('/documents/{document}/pdf', [DocumentOutputController::class, 'pdf'])->name('documents.pdf');
});
