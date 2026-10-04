<?php

namespace Tests\Feature;

use App\Models\Hotel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_can_be_created_read_updated_and_deleted(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);

        $created = $this->postJson('/api/rooms', [
            'hotel_id' => $hotel->id,
            'name' => 'Quarto inicial',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.name', 'Quarto inicial')
            ->assertJsonPath('data.hotel.id', $hotel->id);

        $roomId = $created->json('data.id');

        $this->getJson('/api/rooms')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $roomId);

        $this->getJson("/api/rooms/{$roomId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Quarto inicial');

        $this->patchJson("/api/rooms/{$roomId}", [
            'name' => 'Quarto atualizado',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Quarto atualizado');

        $this->assertDatabaseHas('rooms', [
            'id' => $roomId,
            'name' => 'Quarto atualizado',
        ]);

        $this->deleteJson("/api/rooms/{$roomId}")
            ->assertOk();

        $this->assertDatabaseMissing('rooms', [
            'id' => $roomId,
        ]);

        $this->getJson("/api/rooms/{$roomId}")
            ->assertNotFound();
    }

    public function test_room_creation_requires_a_valid_hotel_and_name(): void
    {
        $this->postJson('/api/rooms', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hotel_id', 'name']);

        $this->postJson('/api/rooms', [
            'hotel_id' => 999999,
            'name' => 'Quarto',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['hotel_id']);

        $this->assertDatabaseCount('rooms', 0);
    }

    public function test_room_with_reservations_cannot_be_deleted(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);

        $room = $hotel->rooms()->create([
            'name' => 'Quarto reservado',
        ]);

        $reservation = $room->reservations()->create([
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-02',
            'total' => '100.00',
        ]);

        $this->deleteJson("/api/rooms/{$room->id}")
            ->assertConflict();

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
        ]);
    }

    public function test_room_update_cannot_change_its_hotel(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel original']);
        $otherHotel = Hotel::create(['name' => 'Outro hotel']);

        $room = $hotel->rooms()->create(['name' => 'Quarto']);

        $this->patchJson("/api/rooms/{$room->id}", [
            'name' => 'Nome alterado',
            'hotel_id' => $otherHotel->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['hotel_id']);

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'hotel_id' => $hotel->id,
            'name' => 'Quarto',
        ]);
    }

    public function test_room_creation_cannot_assign_an_external_id(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);

        $this->postJson('/api/rooms', [
            'hotel_id' => $hotel->id,
            'name' => 'Quarto',
            'external_id' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['external_id']);

        $this->assertDatabaseCount('rooms', 0);
    }
}
