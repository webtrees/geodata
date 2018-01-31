<?php

namespace Webtrees\Geodata;

use DomainException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;
use function property_exists;
use function str_replace;

class RepairCommand extends AbstractBaseCommand
{
    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

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
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws FileNotFoundException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input  = $input;
        $this->output = $output;
        $source       = $this->geographicDataFilesystem();

        $objects = $source->listContents('/', true);

        usort($objects, static function (array $x, array $y) {
            return $x['dirname'] <=> $y['dirname'];
        });

        $folder_objects  = array_filter($objects, static function (array $x): bool {
            return $x['type'] === 'dir';
        });
        $flag_objects    = array_filter($objects, static function (array $x): bool {
            return $x['basename'] === 'flag.svg';
        });
        $geojson_objects = array_filter($objects, static function (array $x): bool {
            return $x['basename'] === 'data.geojson';
        });

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
     */
    private function invalidCharacters(Filesystem $source): void
    {
        $objects = $source->listContents('/', true);

        $child_objects = array_filter($objects, static function (array $x): bool {
            return preg_match("/^[A-Za-z ().'-]+\$/", $x['basename']) !== 1;
        });

        foreach ($child_objects as $child_object) {
            $this->output->writeln($child_object['path'] . ' is not written using ASCII characters');
        }
    }


    /**
     * Create missing GeoJSON files (where we have child data).
     *
     * @param Filesystem $source
     *
     * @return void
     * @throws FileNotFoundException
     */
    private function missingGeojson(Filesystem $source): void
    {
        $objects = $source->listContents('/', true);

        $child_objects = array_filter($objects, static function (array $x): bool {
            return $x['basename'] === 'data.geojson' || $x['basename'] === 'flag.svg';
        });

        foreach ($child_objects as $child_object) {
            $parent_directory = dirname($child_object['dirname']);
            $parent_name      = basename($child_object['dirname']);

            // The top-level doesn't have a parent
            if ($parent_name === '') {
                continue;
            }

            $geojson_file = $parent_directory . '/data.geojson';

            if ($source->has($geojson_file)) {
                $geojson = json_decode($source->read($geojson_file));
            } else {
                $geojson = $this->emptyGeoJsonObject();
            }

            if (!is_object($geojson)) {
                var_dump($geojson_file, $geojson);exit;
            }

            if (!$this->featuresInclude($geojson->features, $parent_name)) {
                $geojson->features[] = (object) [
                    'type' => 'Feature',
                    'id'   => $parent_name,
                ];

                $source->put($geojson_file, $this->formatGeoJson($geojson));
            }
        }
    }

    /**
     * Convert all GeoJSON files to a canonical format.
     *
     * @param Filesystem $filesystem
     *
     * @return void
     * @throws FileNotFoundException
     */
    private function canonicalGeojson(Filesystem $filesystem): void
    {
        $objects = $filesystem->listContents('/', true);

        $geojson_objects = array_filter($objects, static function (array $x): bool {
            return $x['basename'] === 'data.geojson';
        });

        foreach ($geojson_objects as $geojson_object) {
            $path = $geojson_object['path'];
            $raw  = $filesystem->read($path);
            //$raw  = preg_replace('/,\n\s*}/', '}', $raw);

            $geojson = json_decode($raw, false);

            if ($geojson === null) {
                var_dump(json_last_error());exit;
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

            $filesystem->put($path, $this->formatGeoJson($geojson));
        }
    }
}
