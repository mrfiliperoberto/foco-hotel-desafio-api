<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
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

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $room = Room::create($request->validated());

        $room->load('hotel');

        return response()->json([
            'data' => $room,
        ], 201);
    }

    public function show(Room $room): JsonResponse
    {
        $room->load('hotel');

        return response()->json([
            'data' => $room,
        ]);
    }
}