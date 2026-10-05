<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailableRoomsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_available_rooms_from_requested_hotel_are_returned(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel principal']);
        $otherHotel = Hotel::create(['name' => 'Outro hotel']);

        $availableRoom = $hotel->rooms()->create(['name' => 'Quarto livre']);
        $occupiedRoom = $hotel->rooms()->create(['name' => 'Quarto ocupado']);

        $otherHotel->rooms()->create(['name' => 'Quarto de outro hotel']);

        $this->reserve($occupiedRoom, '2026-12-01', '2026-12-04');

        $this->getJson($this->url($hotel->id))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $availableRoom->id)
            ->assertJsonPath('data.0.hotel.id', $hotel->id)
            ->assertJsonPath('total', 1);
    }

    public function test_all_types_of_overlapping_stays_are_excluded(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);

        $periods = [
            ['2026-11-30', '2026-12-02'],
            ['2026-12-02', '2026-12-04'],
            ['2026-11-30', '2026-12-04'],
            ['2026-12-01', '2026-12-03'],
        ];

        foreach ($periods as $index => [$checkIn, $checkOut]) {
            $room = $hotel->rooms()->create([
                'name' => 'Quarto '.$index,
            ]);

            $this->reserve($room, $checkIn, $checkOut);
        }

        $this->getJson($this->url($hotel->id))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    public function test_adjacent_and_distant_reservations_do_not_block_availability(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);
        $room = $hotel->rooms()->create(['name' => 'Quarto disponível']);

        $this->reserve($room, '2026-11-29', '2026-12-01');
        $this->reserve($room, '2026-12-03', '2026-12-05');
        $this->reserve($room, '2026-12-10', '2026-12-12');

        $this->getJson($this->url($hotel->id))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $room->id);
    }

    public function test_hotel_and_dates_are_required(): void
    {
        $this->getJson('/api/rooms/available')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'hotel_id',
                'check_in',
                'check_out',
            ]);
    }

    public function test_unknown_hotel_is_rejected(): void
    {
        $this->getJson($this->url(999))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hotel_id');
    }

    public function test_invalid_date_format_is_rejected(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);

        $this->getJson($this->url($hotel->id, [
            'check_in' => '01/12/2026',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('check_in');
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);

        foreach (['2026-12-01', '2026-11-30'] as $checkOut) {
            $this->getJson($this->url($hotel->id, [
                'check_out' => $checkOut,
            ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('check_out');
        }
    }

    public function test_pagination_preserves_search_filters(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);
        $roomIds = [];

        for ($index = 1; $index <= 16; $index++) {
            $room = $hotel->rooms()->create([
                'name' => 'Quarto '.$index,
            ]);

            $roomIds[] = $room->id;
        }

        $response = $this->getJson($this->url($hotel->id))
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('total', 16);

        $nextPageUrl = $response->json('next_page_url');

        $this->assertIsString($nextPageUrl);

        parse_str(parse_url($nextPageUrl, PHP_URL_QUERY), $query);

        $this->assertSame((string) $hotel->id, $query['hotel_id']);
        $this->assertSame('2026-12-01', $query['check_in']);
        $this->assertSame('2026-12-03', $query['check_out']);
        $this->assertSame('2', $query['page']);

        $this->getJson($this->url($hotel->id, ['page' => 2]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $roomIds[15]);
    }

    public function test_availability_search_does_not_create_or_change_reservations(): void
    {
        $hotel = Hotel::create(['name' => 'Hotel de teste']);
        $room = $hotel->rooms()->create(['name' => 'Quarto de teste']);

        $this->reserve($room, '2026-12-10', '2026-12-12');

        $reservation = $room->reservations()->firstOrFail();
        $before = $reservation->getRawOriginal();

        $this->getJson($this->url($hotel->id))
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->assertDatabaseCount('reservations', 1);

        $reservation->refresh();

        $this->assertSame($before, $reservation->getRawOriginal());
    }

    private function url(int $hotelId, array $overrides = []): string
    {
        $query = array_merge([
            'hotel_id' => $hotelId,
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-03',
        ], $overrides);

        return '/api/rooms/available?'.http_build_query($query);
    }

    private function reserve(Room $room, string $checkIn, string $checkOut): void
    {
        $room->reservations()->create([
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'total' => '100.00',
        ]);
    }
}
