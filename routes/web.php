<?php

use App\Http\Controllers\DocumentOutputController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/documents/{document}/preview', [DocumentOutputController::class, 'preview'])->name('documents.preview');
    Route::get('/documents/{document}/pdf', [DocumentOutputController::class, 'pdf'])->name('documents.pdf');
});
