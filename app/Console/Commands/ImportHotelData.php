<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\Room;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class ImportHotelData extends Command
{
    protected $signature = 'hotel-data:import';

    protected $description = 'Import hotel data from XML files';

    public function handle(): int
    {
        $previousSetting = libxml_use_internal_errors(true);

        try {
            $hotelsXml = $this->readXml('hotels.xml', 'Hotels');
            $roomsXml = $this->readXml('rooms.xml', 'Rooms');

            $counts = DB::transaction(function () use ($hotelsXml, $roomsXml): array {
                $hotelCount = 0;
                $roomCount = 0;
                $seenHotelIds = [];
                $seenRoomIds = [];

                foreach ($hotelsXml->Hotel as $hotelNode) {
                    $externalId = $this->readId((string) $hotelNode['id']);
                    $name = $this->readName($hotelNode);

                    if (isset($seenHotelIds[$externalId])) {
                        throw new RuntimeException(
                            "Hotel repetido no XML: {$externalId}."
                        );
                    }

                    $seenHotelIds[$externalId] = true;

                    Hotel::updateOrCreate(
                        ['external_id' => $externalId],
                        ['name' => $name]
                    );

                    $hotelCount++;
                }

                foreach ($roomsXml->Room as $roomNode) {
                    $externalId = $this->readId((string) $roomNode['id']);
                    $hotelCode = $this->readId((string) $roomNode['hotelCode']);
                    $name = $this->readName($roomNode);

                    if (isset($seenRoomIds[$externalId])) {
                        throw new RuntimeException(
                            "Quarto repetido no XML: {$externalId}."
                        );
                    }

                    $seenRoomIds[$externalId] = true;

                    $hotel = Hotel::where('external_id', $hotelCode)->first();

                    if ($hotel === null) {
                        throw new RuntimeException(
                            "Hotel {$hotelCode} não encontrado para o quarto {$externalId}."
                        );
                    }

                    Room::updateOrCreate(
                        ['external_id' => $externalId],
                        [
                            'hotel_id' => $hotel->id,
                            'name' => $name,
                        ]
                    );

                    $roomCount++;
                }

                return [$hotelCount, $roomCount];
            });

            $this->info("Hotéis processados: {$counts[0]}.");
            $this->info("Quartos processados: {$counts[1]}.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);

            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousSetting);
        }
    }

    private function readXml(string $filename, string $root): SimpleXMLElement
    {
        $path = database_path('xml/'.$filename);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                "Arquivo {$filename} não encontrado ou sem acesso."
            );
        }

        $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);

        if ($xml === false || $xml->getName() !== $root) {
            throw new RuntimeException("XML inválido: {$filename}.");
        }

        return $xml;
    }

    private function readId(string $value): int
    {
        $value = trim($value);

        if (! ctype_digit($value) || (int) $value <= 0) {
            throw new RuntimeException("Identificador inválido: {$value}.");
        }

        return (int) $value;
    }

    private function readName(SimpleXMLElement $node): string
    {
        $name = trim((string) $node->Name);

        if ($name === '' || mb_strlen($name) > 255) {
            throw new RuntimeException('Nome vazio ou maior que 255 caracteres.');
        }

        return $name;
    }
}