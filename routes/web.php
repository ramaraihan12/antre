<?php

use App\Http\Controllers\Staff\ConsoleController;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\QueueActionController;
use App\Http\Controllers\Webhooks\MidtransController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::post('webhooks/midtrans', MidtransController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.midtrans');

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('console')->name('console.')->group(function () {
        Route::get('/', [ConsoleController::class, 'index'])->name('index');
        Route::get('counters/{counter}', [ConsoleController::class, 'show'])->name('show');
        Route::post('counters/{counter}/call-next', [QueueActionController::class, 'callNext'])->name('call-next');
        Route::post('tickets/{ticket}/recall', [QueueActionController::class, 'recall'])->name('recall');
        Route::post('tickets/{ticket}/skip', [QueueActionController::class, 'skip'])->name('skip');
        Route::post('tickets/{ticket}/done', [QueueActionController::class, 'done'])->name('done');
        Route::post('check-in', [QueueActionController::class, 'checkIn'])->name('check-in');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
