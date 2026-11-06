<?php

use App\Http\Controllers\Pub\AblyTokenController;
use App\Http\Controllers\Pub\BookingController;
use App\Http\Controllers\Pub\DisplayController;
use App\Http\Controllers\Pub\HomeController;
use App\Http\Controllers\Pub\TicketController;
use App\Http\Controllers\Pub\WalkInController;
use App\Http\Controllers\Staff\ConsoleController;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\QueueActionController;
use App\Http\Controllers\Webhooks\MidtransController;
use Illuminate\Support\Facades\Route;

/*
 * Public pages. They find rows through a branch slug or an unguessable id and never
 * through a logged-in user, so no tenant is set here.
 */
Route::get('/', HomeController::class)->name('home');

Route::get('q/{branch:slug}', [WalkInController::class, 'show'])->name('queue.join');
Route::post('q/{branch:slug}/tickets', [WalkInController::class, 'store'])->middleware(['throttle:30,1', 'throttle:public-write'])->name('queue.take');
Route::get('t/{ticket:public_id}', [TicketController::class, 'show'])->name('ticket.show');
Route::post('t/{ticket:public_id}/cancel', [TicketController::class, 'cancel'])->middleware('throttle:public-write')->name('ticket.cancel');
Route::get('display/{branch:slug}', DisplayController::class)->name('display');

Route::get('book/{branch:slug}/slots', [BookingController::class, 'slots'])->name('booking.slots');

Route::middleware('expire-holds')->group(function () {
    Route::get('book/{branch:slug}', [BookingController::class, 'create'])->name('booking.create');
    Route::post('book/{branch:slug}', [BookingController::class, 'store'])->middleware(['throttle:20,1', 'throttle:public-write'])->name('booking.store');
    Route::get('b/{booking:public_id}', [BookingController::class, 'show'])->name('booking.show');
    Route::post('b/{booking:public_id}/pay', [BookingController::class, 'pay'])->middleware(['throttle:20,1', 'throttle:public-write'])->name('booking.pay');
    Route::post('b/{booking:public_id}/fake-pay', [BookingController::class, 'fakePay'])->middleware('throttle:public-write')->name('booking.fake-pay');
});

Route::post('webhooks/midtrans', MidtransController::class)
    ->middleware(['throttle:120,1', 'throttle:public-write'])
    ->name('webhooks.midtrans');

Route::get('broadcasting/ably-token', AblyTokenController::class)->middleware('throttle:ably-token')->name('broadcasting.ably-token');

/*
 * Staff area. The tenant middleware limits every model query to the user's business.
 */
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::prefix('console')->name('console.')->group(function () {
        Route::get('/', [ConsoleController::class, 'index'])->name('index');
        Route::get('counters/{counter}', [ConsoleController::class, 'show'])->name('show');

        // Public on the demo too, because the staff login is printed on the landing page.
        Route::middleware('throttle:public-write')->group(function () {
            Route::post('counters/{counter}/call-next', [QueueActionController::class, 'callNext'])->name('call-next');
            Route::post('tickets/{ticket}/recall', [QueueActionController::class, 'recall'])->name('recall');
            Route::post('tickets/{ticket}/skip', [QueueActionController::class, 'skip'])->name('skip');
            Route::post('tickets/{ticket}/done', [QueueActionController::class, 'done'])->name('done');
            Route::post('check-in', [QueueActionController::class, 'checkIn'])->middleware('expire-holds')->name('check-in');
        });
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
