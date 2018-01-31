<?php

namespace Webtrees\Geodata;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use stdClass;
use Symfony\Component\Console\Command\Command;

abstract class AbstractBaseCommand extends Command
{
    // Exit codes.
    protected const SUCCESS = 0;
    protected const ERROR   = 1;

    // Initial format for geojson files.
    protected const JSON_OPTIONS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK;

    // Folder names, and IDs must consist of these ASCII characters.
    protected const ID_REGEX = '/^[a-zA-Z() .!\'-]+$/';

    /**
     * We store geographic data from this filesystem.
     *
     * @return Filesystem
     */
    protected function geographicDataFilesystem(): Filesystem
    {
        $mountpoint = dirname(__DIR__) . '/data';
        $adapter    = new Local($mountpoint);

        return new Filesystem($adapter);
    }

    /**
     * Create a new/empty GeoJSON object.
     *
     * @return stdClass
     */
    protected function emptyGeoJsonObject(): stdClass {
        return (object) [
            'type'     => 'FeatureCollection',
            'features' => [],
        ];
    }

    /**
     * Pretty-print a GeoJSON object.
     *
     * @param stdClass $geojson
     *
     * @return string
     */
    protected function formatGeoJson(stdClass $geojson): string
    {
        // Sort features alphabetically
        usort($geojson->features, static function (stdClass $x, stdClass $y) {
            return $x->id <=> $y->id;
        });

        // Sort properties alphabetically
        foreach ($geojson->features as $feature) {
            if (!empty($feature->properties)) {
                $properties = (array) $feature->properties;
                ksort($properties);
                $feature->properties = (object) $properties;
            }
        }

        // Pretty print.
        $geojson_fmt = json_encode($geojson, self::JSON_OPTIONS);

        // Store co-ordinates on one line, for easier human readability.
        $geojson_fmt = preg_replace(
            '/"coordinates": \[\s*([-0-9.]+),\s*([-0-9.]+)\s*]/',
            '"coordinates": [$1,$2]',
            $geojson_fmt
        );

        return $geojson_fmt;
    }

    /**
     * @param stdClass[] $features
     * @param string     $id
     *
     * @return bool
     */
    protected function featuresInclude(array $features, string $id): bool
    {
        foreach ($features as $feature) {
            if ($feature->id === $id) {
                return true;
            }
        }

        return false;
    }
}
