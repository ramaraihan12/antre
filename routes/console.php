<?php

use Illuminate\Support\Facades\Schedule;

// Unpaid bookings give their slot back after the hold time.
Schedule::command('antre:expire-bookings')->everyMinute()->withoutOverlapping();
