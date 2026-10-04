<?php

use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\RoomController;
use Illuminate\Support\Facades\Route;

Route::apiResource('rooms', RoomController::class);

Route::post('reservations', [ReservationController::class, 'store']);