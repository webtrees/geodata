<?php

namespace Webtrees\Geodata;

use JsonException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCommand extends AbstractBaseCommand
{
    private OutputInterface $output;

    /**
     * Command details, options and arguments
     */
    public function configure(): void
    {
        $this
            ->setName('import')
            ->setDescription('Import geographic data')
            ->setHelp('Import geographic data in webtrees/googlemap (.CSV) format')
            ->setDefinition(
                new InputDefinition([
                    new InputOption(
                        'delimiter',
                        null,
                        InputOption::VALUE_OPTIONAL,
                        'Comma or semicolon',
                        ';'
                    ),
                    new InputArgument(
                        'file',
                        InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                        'CSV file in webtrees/googlemap format'
                    ),
                ])
            );
    }

    /**
     * Run the command
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $delimiter    = $input->getoption('delimiter');
        $enclosure    = '"';
        $escape       = '\\';
        $header_lines = 1;

        $files = $input->getArgument('file');

        foreach ($files as $file) {
            if (!is_readable($file)) {
                $output->writeln('Cannot open ' . $file);

                return self::FAILURE;
            }

            $output->writeln('');
            $output->writeln('Reading ' . $file);
            $output->writeln('');

            $handle = fopen($file, 'rb');

            for ($line = 1; ($csv = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false; ++$line) {
                if ($line <= $header_lines) {
                    $output->writeln('Line ' . $line . ' - skipping “' . implode(',', $csv) . '”');
                    continue;
                }

                $output->writeln('Line ' . $line . ' - processing “' . implode(',', $csv) . '”');

                $level       = $csv[0];
                $place_parts = array_filter(array_slice($csv, 1, 5));
                $longitude   = $this->longitude($csv[6]);
                $latitude    = $this->latitude($csv[7]);

                if ($level + 1 !== count($place_parts)) {
                    $output->writeln('Error at line ' . $line . '.  Level does not match number of place names');
                } else {
                    $this->importCsvLine($place_parts, $longitude, $latitude);
                }
            }

            fclose($handle);
        }

        return self::SUCCESS;
    }

    /**
     * Parse a longitude value
     *
     * @param string $longitude
     *
     * @return float
     */
    private function longitude(string $longitude): float
    {
        $hemisphere = $longitude[0];
        $degrees    = (float)substr($longitude, 1);

        if ($hemisphere === 'W') {
            $degrees = -$degrees;
        }

        return $degrees;
    }

    /**
     * Parse a latitude value
     *
     * @param string $latitude
     *
     * @return float
     */
    private function latitude(string $latitude): float
    {
        $hemisphere = $latitude[0];
        $degrees    = (float)substr($latitude, 1);

        if ($hemisphere === 'S') {
            $degrees = -$degrees;
        }

        return $degrees;
    }

    /**
     * @param string[] $place_parts
     * @param float    $longitude
     * @param float    $latitude
     *
     * @return void
     * @throws JsonException
     */
    private function importCsvLine(array $place_parts, float $longitude, float $latitude): void
    {
        $file  = $this->dataFolder($place_parts) . '/data.geojson';
        $place = array_slice($place_parts, -1)[0];

        if (is_file($file)) {
            $geojson = json_decode(file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
        } else {
            $this->output->writeln('Creating ' . $file);
            $geojson = $this->emptyGeoJsonObject();
        }

        if ($this->featuresInclude($geojson->features, $place)) {
            $this->output->writeln('Updating ' . $place);
        } else {
            $this->output->writeln('Creating ' . $place);
            $geojson->features[] = (object) [
                'type'     => 'Feature',
                'id'       => $place,
                'geometry' => (object)[
                    'type'        => 'Point',
                    'coordinates' => [$longitude, $latitude],
                ],
            ];
        }

        file_put_contents($file, $this->formatGeoJson($geojson));
    }

    /**
     * Find the folder for a place name
     *
     * @param array $place_parts
     *
     * @return string
     */
    private function dataFolder(array $place_parts): string
    {
        $place_parts = array_slice($place_parts, 0, -1);
        $dir         = dirname(__DIR__);

        if (empty($place_parts)) {
            return $dir . '/data/';
        }

        return $dir . '/data/' . implode('/', $place_parts) . '/';
    }
}
