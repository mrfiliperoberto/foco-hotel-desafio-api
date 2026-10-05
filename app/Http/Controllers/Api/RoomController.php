<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailableRoomsRequest;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\Room;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function index(): JsonResponse
    {
        $rooms = Room::with('hotel')
            ->orderBy('id')
            ->paginate(15);

        return response()->json($rooms);
    }

    public function available(AvailableRoomsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $rooms = Room::with('hotel')
            ->where('hotel_id', $data['hotel_id'])
            ->whereDoesntHave('reservations', function (Builder $query) use ($data): void {
                $query->whereDate('check_in', '<', $data['check_out'])
                    ->whereDate('check_out', '>', $data['check_in']);
            })
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

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

    public function update(UpdateRoomRequest $request, Room $room): JsonResponse
    {
        $room->update($request->validated());

        $room->load('hotel');

        return response()->json([
            'data' => $room,
        ]);
    }

    public function destroy(Room $room): JsonResponse
    {
        return DB::transaction(function () use ($room): JsonResponse {
            $lockedRoom = Room::whereKey($room->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRoom->reservations()->exists()) {
                return response()->json([
                    'message' => 'Não é possível excluir um quarto com reservas.',
                ], 409);
            }

            $lockedRoom->delete();

            return response()->json([
                'message' => 'Quarto excluído com sucesso.',
            ]);
        });
    }
}
