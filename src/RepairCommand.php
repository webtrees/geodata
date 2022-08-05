<?php

namespace Webtrees\Geodata;

use DomainException;
use JsonException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use League\Flysystem\StorageAttributes;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function basename;
use function dirname;
use function in_array;
use function json_decode;
use function preg_match;

use function uasort;

use const JSON_THROW_ON_ERROR;

class RepairCommand extends AbstractBaseCommand
{
    private OutputInterface $output;

    /**
     * Command details, options and arguments
     *
     * @return void
     */
    public function configure(): void
    {
        $this
            ->setName('repair')
            ->setDescription('Find and fix errors')
            ->setHelp('Find and fix errors and inconsistencies in the geographic data');
    }

    /**
     * Run the command
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws FilesystemException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $source       = $this->geographicDataFilesystem();

        $this->output->writeln('Check for invalid characters in filenames');
        $this->invalidCharacters($source);
        $this->output->writeln('Check for missing data.geojson files');
        $this->missingGeoJson($source);
        $this->output->writeln('Converting data.geojson files to canonical format');
        $this->canonicalGeoJson($source);

        return self::SUCCESS;
    }

    /**
     * Check for non-ascii characters in file names.
     *
     * @param Filesystem $source
     *
     * @return void
     * @throws FilesystemException
     */
    private function invalidCharacters(Filesystem $source): void
    {
        $bad_filenames = $source->listContents('/', FilesystemReader::LIST_DEEP)
            ->filter(static fn (StorageAttributes $attributes): bool => preg_match("/^[A-Za-z ().'-]+\$/", basename($attributes->path())) !== 1)
            ->map(static fn (StorageAttributes $attributes): string => $attributes->path());

        foreach ($bad_filenames as $bad_filename) {
            $this->output->writeln($bad_filename . ' is not written using ASCII characters');
        }
    }


    /**
     * Create missing GeoJSON files (where we have child data).
     *
     * @param Filesystem $source
     *
     * @return void
     * @throws FilesystemException
     */
    private function missingGeojson(Filesystem $source): void
    {
        $folders = $source->listContents('/', true)
            ->filter(static fn (StorageAttributes $attributes): bool => $attributes->isDir())
            ->map(static fn (StorageAttributes $attributes): string => $attributes->path());

        foreach ($folders as $folder) {
            $parent_directory = dirname($folder);
            $parent_name      = basename($folder);

            // The top-level doesn't have a parent
            if ($folder === '') {
                continue;
            }

            $geojson_file = $parent_directory . '/data.geojson';

            if ($source->has($geojson_file)) {
                try {
                    $geojson = json_decode($source->read($geojson_file), false, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $ex) {
                    $this->output->writeln($geojson_file . ': ' . $ex->getMessage());
                    continue;
                }
            } else {
                $geojson = $this->emptyGeoJsonObject();
            }

            if (!$this->featuresInclude($geojson->features, $parent_name)) {
                $geojson->features[] = (object) [
                    'type' => 'Feature',
                    'id'   => $parent_name,
                ];

                $source->write($geojson_file, $this->formatGeoJson($geojson));
            }
        }
    }

    /**
     * Convert all GeoJSON files to a canonical format.
     *
     * @param Filesystem $filesystem
     *
     * @return void
     * @throws FilesystemException
     */
    private function canonicalGeojson(Filesystem $filesystem): void
    {
        $geojson_objects = $filesystem->listContents('/', true)
            ->filter(static fn (StorageAttributes $attributes): bool => basename($attributes->path()) === 'data.geojson')
            ->map(static fn (StorageAttributes $attributes): string => $attributes->path());

        foreach ($geojson_objects as $path) {
            $raw  = $filesystem->read($path);
            //$raw  = preg_replace('/,\n\s*}/', '}', $raw);

            try {
                $geojson = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $ex) {
                $this->output->writeln($geojson_object . ': ' . $ex->getMessage());
                continue;
            }

            // Type
            $geojson->type = 'FeatureCollection';

            // Features
            $geojson->features = $geojson->features ?? [];

            // Keep track of IDs to check for duplicates.
            $ids = [];

            foreach ($geojson->features as $feature_key => $feature) {
                if (in_array($feature->id, $ids, true)) {
                    throw new DomainException('Duplicate ID: ' . $feature->id);
                }

                $ids[] = $feature->id;

                // Type
                $type = $feature->type ??= 'Feature';

                $geometry = $feature->geometry ??= (object) [
                    'type' => 'Point',
                    'coordinates' => [0,0],
                ];

                // Properties
                $properties = $feature->properties ??= [];

                // Remove redundant properties
                foreach ($properties as $language => $local_name) {
                    if ($local_name === $feature->id) {
                        unset($feature->properties->$language);
                    }
                }

                // Store the properties in a defined order.
                $geojson->features[$feature_key] = (object) [
                    'id' => $feature->id,
                    'type' => $type,
                    'geometry' => $geometry,
                    'properties' => $properties,
                ];
            }

            uasort($geojson->features, static fn (object $x, object $y): int => $x->id <=> $y->id);

            $filesystem->write($path, $this->formatGeoJson($geojson));
        }
    }
}
