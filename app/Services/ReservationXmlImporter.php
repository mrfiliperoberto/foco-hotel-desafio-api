<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ReservationXmlImporter
{
    public function import(): array
    {
        $path = database_path('xml/reserves.xml');

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Arquivo reserves.xml indisponível.');
        }

        $contents = file_get_contents($path);

        if ($contents === false || stripos($contents, '<!DOCTYPE') !== false) {
            throw new RuntimeException('XML indisponível ou com DOCTYPE não permitido.');
        }

        $previousSetting = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string(
                $contents,
                'SimpleXMLElement',
                LIBXML_NONET
            );

            if (
                $xml === false
                || $xml->getName() !== 'Reserves'
                || count($xml->Reserve) === 0
            ) {
                throw new RuntimeException('XML de reservas inválido ou vazio.');
            }

            $result = DB::transaction(function () use ($xml): array {
                $count = 0;
                $warnings = [];
                $seenIds = [];

                foreach ($xml->Reserve as $node) {
                    $data = [
                        'external_id' => trim((string) $node['id']),
                        'hotel_code' => trim((string) $node['hotelCode']),
                        'room_code' => trim((string) $node['roomCode']),
                        'check_in' => trim((string) $node->CheckIn),
                        'check_out' => trim((string) $node->CheckOut),
                        'total' => trim((string) $node->Total),
                        'guests' => [],
                        'dailies' => [],
                        'payments' => [],
                    ];

                    foreach ($node->Guests->Guest as $guest) {
                        $data['guests'][] = [
                            'first_name' => trim((string) $guest->Name),
                            'last_name' => trim((string) $guest->LastName),
                            'phone' => trim((string) $guest->Phone),
                        ];
                    }

                    foreach ($node->Dailies->Daily as $daily) {
                        $data['dailies'][] = [
                            'date' => trim((string) $daily->Date),
                            'value' => trim((string) $daily->Value),
                        ];
                    }

                    if (isset($node->Payments)) {
                        foreach ($node->Payments->Payment as $payment) {
                            $data['payments'][] = [
                                'method' => trim((string) $payment->Method),
                                'value' => trim((string) $payment->Value),
                            ];
                        }
                    }

                    $this->validate($data);

                    $externalId = (int) $data['external_id'];

                    if (isset($seenIds[$externalId])) {
                        throw new RuntimeException(
                            "Reserva repetida no XML: {$externalId}."
                        );
                    }

                    $seenIds[$externalId] = true;

                    $room = Room::with('hotel')
                        ->where('external_id', (int) $data['room_code'])
                        ->lockForUpdate()
                        ->first();

                    if (
                        $room === null
                        || $room->hotel === null
                        || (int) $room->hotel->external_id !== (int) $data['hotel_code']
                    ) {
                        throw new RuntimeException(
                            "Quarto ou hotel incompatível na reserva {$externalId}."
                        );
                    }

                    $dailyTotal = 0;

                    foreach ($data['dailies'] as $daily) {
                        $dailyTotal += $this->toCents($daily['value']);

                        if (
                            $daily['date'] < $data['check_in']
                            || $daily['date'] >= $data['check_out']
                        ) {
                            $warnings[] = "Reserva {$externalId}: diária "
                                .$daily['date']
                                .' fora do período da estadia; valor original preservado.';
                        }
                    }

                    $total = $this->toCents($data['total']);

                    if ($dailyTotal !== $total) {
                        throw new RuntimeException(
                            "Soma das diárias diferente do total na reserva {$externalId}."
                        );
                    }

                    $checkIn = new DateTimeImmutable($data['check_in']);
                    $checkOut = new DateTimeImmutable($data['check_out']);
                    $nights = (int) $checkIn->diff($checkOut)->days;

                    if (count($data['dailies']) !== $nights) {
                        $warnings[] = "Reserva {$externalId}: quantidade de diárias "
                            .'diferente da quantidade de noites; dados originais preservados.';
                    }

                    $paid = 0;

                    foreach ($data['payments'] as $payment) {
                        $paid += $this->toCents($payment['value']);
                    }

                    if ($paid > $total) {
                        throw new RuntimeException(
                            "Pagamentos acima do total na reserva {$externalId}."
                        );
                    }

                    $reservation = Reservation::updateOrCreate(
                        ['external_id' => $externalId],
                        [
                            'room_id' => $room->id,
                            'check_in' => $data['check_in'],
                            'check_out' => $data['check_out'],
                            'total' => $data['total'],
                        ]
                    );

                    $reservation->guests()->delete();
                    $reservation->dailies()->delete();
                    $reservation->payments()->delete();

                    $reservation->guests()->createMany($data['guests']);
                    $reservation->dailies()->createMany($data['dailies']);
                    $reservation->payments()->createMany($data['payments']);

                    $count++;
                }

                return [
                    'reservations' => $count,
                    'warnings' => $warnings,
                ];
            });

            foreach ($result['warnings'] as $warning) {
                Log::warning($warning);
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
        }
    }

    private function validate(array $data): void
    {
        $moneyRules = [
            'required',
            'string',
            'regex:/^\d{1,10}(?:\.\d{1,2})?$/',
        ];

        $idRules = [
            'required',
            'integer',
            'min:1',
            'max:'.PHP_INT_MAX,
        ];

        $validator = Validator::make($data, [
            'external_id' => $idRules,
            'hotel_code' => $idRules,
            'room_code' => $idRules,
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => [
                'required',
                'date_format:Y-m-d',
                'after:check_in',
            ],
            'total' => $moneyRules,
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.first_name' => ['required', 'string', 'max:255'],
            'guests.*.last_name' => ['required', 'string', 'max:255'],
            'guests.*.phone' => ['required', 'string', 'max:255'],
            'dailies' => ['required', 'array', 'min:1'],
            'dailies.*.date' => [
                'required',
                'date_format:Y-m-d',
                'distinct',
            ],
            'dailies.*.value' => $moneyRules,
            'payments' => ['present', 'array'],
            'payments.*.method' => ['required', 'string', 'max:255'],
            'payments.*.value' => $moneyRules,
        ]);

        if ($validator->fails()) {
            throw new RuntimeException(
                'Reserva '.$data['external_id'].': '
                .$validator->errors()->first()
            );
        }
    }

    private function toCents(string $value): int
    {
        $parts = explode('.', $value, 2);
        $whole = (int) $parts[0];
        $fraction = str_pad($parts[1] ?? '', 2, '0');

        return ($whole * 100) + (int) $fraction;
    }
}
