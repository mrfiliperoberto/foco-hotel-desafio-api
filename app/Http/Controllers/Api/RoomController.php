<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\JsonResponse;

class RoomController extends Controller
{
    public function index(): JsonResponse
    {
        $rooms = Room::with('hotel')
            ->orderBy('id')
            ->paginate(15);

        return response()->json($rooms);
    }

    public function show(Room $room): JsonResponse
    {
        $room->load('hotel');

        return response()->json([
            'data' => $room,
        ]);
    }
}