<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReservationService
{
    public function create(array $data): Reservation
    {
        $checkIn = new DateTimeImmutable($data['check_in']);
        $checkOut = new DateTimeImmutable($data['check_out']);
        $nights = (int) $checkIn->diff($checkOut)->days;

        if (count($data['dailies']) !== $nights) {
            throw ValidationException::withMessages([
                'dailies' => 'Informe uma diária para cada noite da estadia.',
            ]);
        }

        $dailyTotal = 0;

        foreach ($data['dailies'] as $daily) {
            $dailyTotal += $this->toCents($daily['value']);
        }

        $total = $this->toCents($data['total']);

        if ($dailyTotal !== $total) {
            throw ValidationException::withMessages([
                'total' => 'O total deve corresponder à soma das diárias.',
            ]);
        }

        $payments = $data['payments'] ?? [];
        $paid = 0;

        foreach ($payments as $payment) {
            $paid += $this->toCents($payment['value']);
        }

        if ($paid > $total) {
            throw ValidationException::withMessages([
                'payments' => 'Os pagamentos não podem ultrapassar o total.',
            ]);
        }

        return DB::transaction(function () use ($data, $payments): Reservation {
            $room = Room::whereKey($data['room_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $hasConflict = $room->reservations()
                ->whereDate('check_in', '<', $data['check_out'])
                ->whereDate('check_out', '>', $data['check_in'])
                ->exists();

            if ($hasConflict) {
                throw new ConflictHttpException(
                    'O quarto já possui uma reserva nesse período.'
                );
            }

            $reservation = $room->reservations()->create([
                'check_in' => $data['check_in'],
                'check_out' => $data['check_out'],
                'total' => $data['total'],
            ]);

            $reservation->guests()->createMany($data['guests']);
            $reservation->dailies()->createMany($data['dailies']);
            $reservation->payments()->createMany($payments);

            return $reservation->load([
                'room.hotel',
                'guests',
                'dailies',
                'payments',
            ]);
        });
    }

    private function toCents(string $value): int
    {
        $parts = explode('.', $value, 2);
        $whole = (int) $parts[0];
        $fraction = str_pad($parts[1] ?? '', 2, '0');

        return ($whole * 100) + (int) $fraction;
    }
}
