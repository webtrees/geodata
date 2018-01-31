<?php

namespace Webtrees\Geodata;

use League\Flysystem\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RepairCommand extends AbstractBaseCommand
{
    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /**
     * Command details, options and arguments
     */
    public function configure()
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
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;
        $source       = $this->geographicDataFilesystem();

        $objects = $source->listContents('/', true);

        usort($objects, function (array $x, array $y) {
            return $x['dirname'] <=> $y['dirname'];
        });

        $folder_objects  = array_filter($objects, function (array $x): bool {
            return $x['type'] === 'dir';
        });
        $flag_objects    = array_filter($objects, function (array $x): bool {
            return $x['basename'] === 'flag.svg';
        });
        $geojson_objects = array_filter($objects, function (array $x): bool {
            return $x['basename'] === 'data.geojson';
        });

        $this->output->writeln('Check for missing data.geojson files');
        $this->missingGeoJson($source);
        $this->output->writeln('Converting data.geojson files to canonical format');
        $this->canonicalGeoJson($source);

    }

    /**
     * Create missing GeoJSON files (where we have child data).
     *
     * @param Filesystem $source
     */
    private function missingGeojson(Filesystem $source)
    {
        $objects = $source->listContents('/', true);

        $child_objects = array_filter($objects, function (array $x): bool {
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
     * @param Filesystem $source
     */
    private function canonicalGeojson(Filesystem $source) {
        $objects = $source->listContents('/', true);

        $geojson_objects = array_filter($objects, function (array $x): bool {
            return $x['basename'] === 'data.geojson';
        });

        foreach ($geojson_objects as $geojson_object) {
            $path = $geojson_object['path'];

            $geojson = json_decode($source->read($path));

            // Type
            $geojson->type = 'FeatureCollection';

            // Features
            if (empty($geojson->features)) {
                $geojson->features = [];
            }

            foreach ($geojson->features as $feature) {
                // Type
                $feature->type = 'Feature';
                // Apostrophes
                $feature->id = str_replace('\'', '’', $feature->id);
                if (!empty($feature->properties)) {
                    foreach ($feature->properties as $key => $value) {
                        $feature->properties->$key = str_replace('\'', '’', $value);
                    }
                }
            }

            $source->put($path, $this->formatGeoJson($geojson));
        }
    }
}
