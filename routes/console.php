<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('hotel-data:import')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/import.log'));