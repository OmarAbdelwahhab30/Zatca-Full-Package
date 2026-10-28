<?php

use Illuminate\Support\Facades\Route;
use Zatca\Dashboard\Http\Controllers\DashboardController;
use Zatca\Dashboard\Http\Controllers\InvoiceController;
use Zatca\Dashboard\Http\Controllers\OnboardingController;

Route::get('/', [DashboardController::class, 'index'])->name('overview');

Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding');
Route::post('/onboarding/{egs}/{step}', [OnboardingController::class, 'run'])->name('onboarding.run');

Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
Route::get('/invoices/{invoice}/xml/{variant}', [InvoiceController::class, 'xml'])->name('invoices.xml');
Route::post('/scenarios/{scenario}', [InvoiceController::class, 'run'])->name('scenarios.run');
