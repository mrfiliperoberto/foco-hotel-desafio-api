<?php

use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RoomController;
use Illuminate\Support\Facades\Route;

Route::get('rooms/available', [RoomController::class, 'available'])
    ->name('rooms.available');

Route::apiResource('rooms', RoomController::class);

Route::post('reservations', [ReservationController::class, 'store']);
