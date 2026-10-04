<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $hotel = Hotel::create(['name' => 'Hotel de teste']);

        $this->room = $hotel->rooms()->create([
            'name' => 'Quarto de teste',
        ]);
    }

    private function payload(): array
    {
        return [
            'room_id' => $this->room->id,
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-03',
            'total' => '200.00',
            'guests' => [
                [
                    'first_name' => 'Ana',
                    'last_name' => 'Silva',
                    'phone' => '11999999999',
                ],
            ],
            'dailies' => [
                ['date' => '2026-12-01', 'value' => '100.00'],
                ['date' => '2026-12-02', 'value' => '100.00'],
            ],
            'payments' => [
                ['method' => '1', 'value' => '50.00'],
            ],
        ];
    }

    private function assertNoReservationData(): void
    {
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('reservation_guests', 0);
        $this->assertDatabaseCount('reservation_dailies', 0);
        $this->assertDatabaseCount('reservation_payments', 0);
    }

    public function test_reservation_is_created_with_related_records(): void
    {
        $response = $this->postJson('/api/reservations', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.room_id', $this->room->id)
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonPath('data.guests.0.first_name', 'Ana')
            ->assertJsonCount(2, 'data.dailies')
            ->assertJsonPath('data.payments.0.value', '50.00');

        $reservationId = $response->json('data.id');

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'room_id' => $this->room->id,
            'external_id' => null,
        ]);

        $this->assertDatabaseHas('reservation_guests', [
            'reservation_id' => $reservationId,
            'first_name' => 'Ana',
        ]);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_guests', 1);
        $this->assertDatabaseCount('reservation_dailies', 2);
        $this->assertDatabaseCount('reservation_payments', 1);
    }

    public function test_overlapping_reservation_is_rejected_without_extra_records(): void
    {
        $this->postJson('/api/reservations', $this->payload())
            ->assertCreated();

        $data = $this->payload();
        $data['check_in'] = '2026-12-02';
        $data['check_out'] = '2026-12-04';
        $data['dailies'] = [
            ['date' => '2026-12-02', 'value' => '100.00'],
            ['date' => '2026-12-03', 'value' => '100.00'],
        ];

        $this->postJson('/api/reservations', $data)
            ->assertConflict();

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_guests', 1);
        $this->assertDatabaseCount('reservation_dailies', 2);
        $this->assertDatabaseCount('reservation_payments', 1);
    }

    public function test_check_in_on_previous_check_out_date_is_allowed(): void
    {
        $this->postJson('/api/reservations', $this->payload())
            ->assertCreated();

        $data = $this->payload();
        $data['check_in'] = '2026-12-03';
        $data['check_out'] = '2026-12-05';
        $data['dailies'] = [
            ['date' => '2026-12-03', 'value' => '100.00'],
            ['date' => '2026-12-04', 'value' => '100.00'],
        ];

        $this->postJson('/api/reservations', $data)
            ->assertCreated();

        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_total_must_match_daily_values(): void
    {
        $data = $this->payload();
        $data['total'] = '250.00';

        $this->postJson('/api/reservations', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total']);

        $this->assertNoReservationData();
    }

    public function test_payments_cannot_exceed_total(): void
    {
        $data = $this->payload();
        $data['payments'][0]['value'] = '200.01';

        $this->postJson('/api/reservations', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payments']);

        $this->assertNoReservationData();
    }

    public function test_daily_on_check_out_date_is_rejected(): void
    {
        $data = $this->payload();
        $data['dailies'][1]['date'] = '2026-12-03';

        $this->postJson('/api/reservations', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dailies.1.date']);

        $this->assertNoReservationData();
    }

    public function test_repeated_daily_dates_are_rejected(): void
    {
        $data = $this->payload();
        $data['dailies'][1]['date'] = '2026-12-01';

        $this->postJson('/api/reservations', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dailies.1.date']);

        $this->assertNoReservationData();
    }

    public function test_each_night_requires_a_daily(): void
    {
        $data = $this->payload();
        $data['check_out'] = '2026-12-04';

        $this->postJson('/api/reservations', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dailies']);

        $this->assertNoReservationData();
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        $data = $this->payload();
        $data['check_out'] = $data['check_in'];

        $this->postJson('/api/reservations', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['check_out']);

        $this->assertNoReservationData();
    }

    public function test_reservation_can_be_created_without_payments(): void
    {
        $data = $this->payload();
        unset($data['payments']);

        $this->postJson('/api/reservations', $data)
            ->assertCreated()
            ->assertJsonCount(0, 'data.payments');

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('reservation_payments', 0);
    }

    public function test_unknown_room_is_rejected(): void
    {
        $data = $this->payload();
        $data['room_id'] = 999999;

        $this->postJson('/api/reservations', $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);

        $this->assertNoReservationData();
    }
}
