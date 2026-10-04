<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class XmlImportTest extends TestCase
{
    use RefreshDatabase;

    private function runImport(): void
    {
        $this->artisan('hotel-data:import')
            ->expectsOutput('Hotéis processados: 3.')
            ->expectsOutput('Quartos processados: 6.')
            ->expectsOutput('Reservas processadas: 6.')
            ->expectsOutput(
                'Reserva 6: diária 2022-12-03 fora do período da estadia; valor original preservado.'
            )
            ->assertExitCode(0);
    }

    private function assertImportedCounts(): void
    {
        $this->assertDatabaseCount('hotels', 3);
        $this->assertDatabaseCount('rooms', 6);
        $this->assertDatabaseCount('reservations', 6);
        $this->assertDatabaseCount('reservation_guests', 6);
        $this->assertDatabaseCount('reservation_dailies', 18);
        $this->assertDatabaseCount('reservation_payments', 1);
    }

    public function test_original_xml_files_are_imported_with_correct_relationships(): void
    {
        $this->runImport();
        $this->assertImportedCounts();

        $hotel = Hotel::where('external_id', 1)->firstOrFail();

        $this->assertSame('Hotel Foco Prime', $hotel->name);

        $room = Room::where('external_id', 1)->firstOrFail();

        $this->assertSame($hotel->id, $room->hotel_id);

        $reservation = Reservation::where('external_id', 1)
            ->firstOrFail();

        $this->assertSame($room->id, $reservation->room_id);
        $this->assertSame('2022-12-01', $reservation->check_in->format('Y-m-d'));
        $this->assertSame('2022-12-04', $reservation->check_out->format('Y-m-d'));
        $this->assertSame('300.00', $reservation->total);

        $this->assertSame(
            'Fulaninho',
            $reservation->guests()->firstOrFail()->first_name
        );

        $this->assertSame(
            '100.00',
            $reservation->payments()->firstOrFail()->value
        );
    }

    public function test_repeated_import_does_not_duplicate_records(): void
    {
        $this->runImport();

        $originalIds = [
            'hotels' => Hotel::orderBy('external_id')->pluck('id')->all(),
            'rooms' => Room::orderBy('external_id')->pluck('id')->all(),
            'reservations' => Reservation::orderBy('external_id')->pluck('id')->all(),
        ];

        $this->runImport();
        $this->assertImportedCounts();

        $this->assertSame(
            $originalIds['hotels'],
            Hotel::orderBy('external_id')->pluck('id')->all()
        );

        $this->assertSame(
            $originalIds['rooms'],
            Room::orderBy('external_id')->pluck('id')->all()
        );

        $this->assertSame(
            $originalIds['reservations'],
            Reservation::orderBy('external_id')->pluck('id')->all()
        );
    }

    public function test_import_restores_values_from_source_xml(): void
    {
        $this->runImport();

        Hotel::where('external_id', 1)->update([
            'name' => 'Nome alterado',
        ]);

        Reservation::where('external_id', 1)->update([
            'total' => '999.00',
        ]);

        $this->runImport();

        $this->assertSame(
            'Hotel Foco Prime',
            Hotel::where('external_id', 1)->firstOrFail()->name
        );

        $this->assertSame(
            '300.00',
            Reservation::where('external_id', 1)->firstOrFail()->total
        );

        $this->assertImportedCounts();
    }

    public function test_inconsistent_daily_is_preserved_with_warning(): void
    {
        $this->runImport();

        $reservation = Reservation::where('external_id', 6)
            ->firstOrFail();

        $this->assertSame(
            '2022-10-04',
            $reservation->check_out->format('Y-m-d')
        );

        $daily = $reservation->dailies()
            ->whereDate('date', '2022-12-03')
            ->firstOrFail();

        $this->assertSame('150.00', $daily->value);
        $this->assertSame('450.00', $reservation->total);
    }
}