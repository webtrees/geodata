<?php

namespace Webtrees\Geodata;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TranslateCommand extends AbstractBaseCommand
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
            ->setName('import')
            ->setDescription('Add a translation')
            ->setHelp('Add a translation to an existing place name')
            ->setDefinition(
                new InputDefinition([
                    new InputArgument(
                        'place',
                        InputArgument::REQUIRED,
                        'Name of place (in English), e.g. "England/London"'
                    ),
                    new InputArgument(
                        'language',
                        InputArgument::REQUIRED,
                        'Language code, e.g. "fr"'
                    ),
                    new InputArgument(
                        'translation',
                        InputArgument::REQUIRED,
                        'e.g. "Londres" (leave empty to delete existing translation)'
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
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;

        $place       = $input->getArgument('place');
        $language    = $input->getArgument('language');
        $translation = $input->getArgument('translation');
        $source      = $this->geographicDataFilesystem();
        $file        = dirname($place) . '/data.geojson';
        $placename   = basename($place);
        $geojson     = json_decode($source->read($file));

        foreach ($geojson->features as $feature) {
            if ($feature->id === $placename) {
                $feature->attributes = $feature->attributes ?? (object) [];
                $feature->attributes->$language = $translation;
                $this->output->writeln('Setting ' . $language . '/' . $placename . ' to ' . $translation);
                $source->write($file, $this->formatGeoJson($geojson));
                break;
            }
        }
    }
}
