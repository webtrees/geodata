<?php

namespace Webtrees\Geodata;

use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\ImageManager;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends AbstractBaseCommand
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
            ->setName('export')
            ->setDescription('Export geographic data')
            ->setHelp('Export geographic data in webtrees/googlemap format')
            ->setDefinition(
                new InputDefinition([
                    new InputOption(
                        'language',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'Language code',
                        'en'
                    ),
                    new InputOption(
                        'prefix',
                        null,
                        InputOption::VALUE_OPTIONAL,
                        'Extract only places beginning with this prefix',
                        ''
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
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input  = $input;
        $this->output = $output;
        $language     = $input->getoption('language');
        $prefix       = $input->getoption('prefix');
        $source       = $this->geographicDataFilesystem();
        $destination  = $this->destinationFilesystem($language);

        $this->exportData($source, $destination, $language, $prefix);

        return self::ERROR;
    }

    /**
     * Export geographic data to this filesystem.
     *
     * @param string $language
     *
     * @return Filesystem
     */
    private function destinationFilesystem(string $language): Filesystem
    {
        $mountpoint = dirname(__DIR__) . '/dist/places-' . $language . '.zip';

        if (file_exists($mountpoint)) {
            unlink($mountpoint);
        }

        $adapter = new ZipArchiveAdapter($mountpoint);

        return new Filesystem($adapter);
    }

    /**
     * Export the data
     *
     * @param Filesystem $source
     * @param Filesystem $destination
     * @param string $language
     * @param string $prefix
     *
     * @throws FileExistsException
     * @throws FileNotFoundException
     * @void
     */
    private function exportData(Filesystem $source, Filesystem $destination, string $language, string $prefix): void
    {
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

        $translations = [];

        foreach ($geojson_objects as $geojson_object) {
            $geojson = json_decode($source->read($geojson_object['path']), false);

            if (!empty($geojson->features)) {
                $parent = $geojson_object['dirname'];

                if ($parent === '') {
                    $translated_parent = '';
                } else {
                    $translated_parent = $translations[$parent] . '/';
                    $parent            .= '/';
                }

                foreach ($geojson->features as $feature) {
                    $translations[$parent . $feature->id] = $translated_parent . ($feature->properties->$language ?? $feature->id);
                }
            }
        }

        foreach ($flag_objects as $flag_object) {
            if ($this->isPrefix($flag_object, $prefix)) {
                $file = $translations[$flag_object['dirname']] . '.png';
                $svg  = $source->read($flag_object['path']);

                try {
                    $png = $this->createPngFromImage($svg, 25, 15);
                    $destination->write('places/flags/' . $file, $png);
                } catch (NotReadableException $ex) {
                    $this->output->writeln('Failed to create flag for ' . $flag_object['path']);
                    $this->output->writeln($ex->getMessage());
                }
            }
        }
    }

    /**
     * Does a filename match a prefix
     *
     * @param array  $object
     * @param string $prefix
     *
     * @return bool
     */
    private function isPrefix(array $object, string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }

        return strpos($object['path'], $prefix . '/') === 0;
    }

    /**
     * Convert an image to PNG format
     *
     * @param string $svg
     * @param int    $width
     * @param int    $height
     *
     * @return string
     */
    private function createPngFromImage(string $svg, int $width, int $height): string
    {
        $image_manager = new ImageManager(['driver' => 'imagick']);

        $image = $image_manager
            ->make($svg)
            ->fit($width, $height)
            ->resizeCanvas($width, $height);

        $png = (string)$image->encode('png');

        $image->destroy();

        return $png;
    }

    /**
     * Convert a latitude value
     *
     * @param float $latitude
     *
     * @return string
     */
    private function latitude(float $latitude): string
    {
        if ($latitude < 0) {
            return 'S' . abs($latitude);
        }

        return 'N' . $latitude;
    }

    /**
     * Convert a longitude value
     *
     * @param float $longitude
     *
     * @return string
     */
    private function longitude(float $longitude): string
    {
        if ($longitude < 0) {
            return 'W' . abs($longitude);
        }

        return 'E' . $longitude;
    }
}
