<?php

namespace Webtrees\Geodata;

use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\ImageManager;
use JsonException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemReader;
use League\Flysystem\StorageAttributes;
use League\Flysystem\ZipArchive\FilesystemZipArchiveProvider;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function abs;
use function array_filter;
use function basename;
use function dirname;
use function file_exists;
use function json_decode;
use function str_starts_with;
use function unlink;
use function usort;

use const JSON_THROW_ON_ERROR;

class ExportCommand extends AbstractBaseCommand
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
     * @throws FilesystemException|JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $language     = $input->getoption('language');
        $prefix       = $input->getoption('prefix');
        $source       = $this->geographicDataFilesystem();
        $destination  = $this->destinationFilesystem($language);

        $this->exportData($source, $destination, $language, $prefix);

        return self::FAILURE;
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

        $adapter = new ZipArchiveAdapter(new FilesystemZipArchiveProvider($mountpoint));

        return new Filesystem($adapter);
    }

    /**
     * Export the data
     *
     * @param Filesystem $source
     * @param Filesystem $destination
     * @param string     $language
     * @param string     $prefix
     *
     * @throws FilesystemException
     * @throws JsonException
     * @void
     */
    private function exportData(Filesystem $source, Filesystem $destination, string $language, string $prefix): void
    {
        $objects = $source->listContents('/', FilesystemReader::LIST_DEEP)->map(static fn (StorageAttributes $attributes): string => $attributes->path())->toArray();
        usort($objects, static fn (string $x, string $y): int => dirname($x) <=> dirname($y));

        $flag_files    = array_filter($objects, static fn (string $x): bool => basename($x) === 'flag.svg' && str_starts_with($x, $prefix));
        $geojson_files = array_filter($objects, static fn (string $x): bool => basename($x) === 'data.geojson');

        $translations = [];

        foreach ($geojson_files as $geojson_file) {
            $this->output->writeln('Processing ' . $geojson_file);

            $geojson = json_decode($source->read($geojson_file), false, 512, JSON_THROW_ON_ERROR);

            $parent = dirname($geojson_file);

            if ($parent === '.') {
                $translated_parent = '';
                $parent = '';
            } else {
                $translated_parent = $translations[$parent] . '/';
                $parent            .= '/';
            }

            foreach ($geojson->features as $feature) {
                $translations[$parent . $feature->id] = $translated_parent . ($feature->properties->$language ?? $feature->id);
            }
        }

        foreach ($flag_files as $flag_file) {
            $file = $translations[dirname($flag_file)] . '.png';
            $svg  = $source->read($flag_file);

            try {
                $this->output->writeln('Creating ' . $file);
                $png = $this->createPngFromImage($svg, 25, 15);
                $destination->write('places/flags/' . $file, $png);
                $this->output->writeln('Created ' . $file);
            } catch (NotReadableException $ex) {
                $this->output->writeln('Failed to create flag for ' . $flag_file);
                $this->output->writeln($ex->getMessage());
            }
        }
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

        $png = (string) $image->encode('png');

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
