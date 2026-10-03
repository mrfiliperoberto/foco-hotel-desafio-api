<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportHotelData extends Command
{
    protected $signature = 'hotel-data:import';

    protected $description = 'Import hotel data from XML files';

    public function handle(): int
    {
        $path = database_path('xml/hotels.xml');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Arquivo hotels.xml não encontrado ou sem acesso.');

            return self::FAILURE;
        }

        $previousSetting = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET);

            if ($xml === false || $xml->getName() !== 'Hotels') {
                throw new RuntimeException('O XML de hotéis é inválido.');
            }

            $count = DB::transaction(function () use ($xml): int {
                $count = 0;
                $seenIds = [];

                foreach ($xml->Hotel as $hotelNode) {
                    $externalId = trim((string) $hotelNode['id']);
                    $name = trim((string) $hotelNode->Name);

                    if (! ctype_digit($externalId) || (int) $externalId <= 0) {
                        throw new RuntimeException('Hotel com identificador inválido.');
                    }

                    $externalId = (int) $externalId;

                    if ($name === '' || mb_strlen($name) > 255) {
                        throw new RuntimeException(
                            "Nome inválido no hotel {$externalId}."
                        );
                    }

                    if (isset($seenIds[$externalId])) {
                        throw new RuntimeException(
                            "Identificador repetido no XML: {$externalId}."
                        );
                    }

                    $seenIds[$externalId] = true;

                    Hotel::updateOrCreate(
                        ['external_id' => $externalId],
                        ['name' => $name]
                    );

                    $count++;
                }

                return $count;
            });

            $this->info("Hotéis processados: {$count}.");

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
}