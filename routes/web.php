<?php

use App\Http\Controllers\Webhooks\MidtransController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::post('webhooks/midtrans', MidtransController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.midtrans');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
