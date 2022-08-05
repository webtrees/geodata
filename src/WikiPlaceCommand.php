<?php

namespace Webtrees\Geodata;

use DomXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use League\Flysystem\FilesystemException;
use Masterminds\HTML5;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function basename;
use function dirname;
use function json_decode;
use function preg_match;
use function rawurldecode;
use function strlen;

use const JSON_THROW_ON_ERROR;

class WikiPlaceCommand extends AbstractBaseCommand
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
            ->setName('import')
            ->setDescription('Import a place')
            ->setHelp('Import place details from en.wikimedia.org')
            ->setDefinition(
                new InputDefinition([
                    new InputArgument(
                        'place',
                        InputArgument::REQUIRED,
                        'Name of place (in English)'
                    ),
                    new InputArgument(
                        'wikipage',
                        InputArgument::REQUIRED,
                        'The URL fragment after "https://en.wikipedia.org/wiki/"'
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
     * @throws FilesystemException
     * @throws GuzzleException
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $place    = $input->getArgument('place');
        $wikipage = $input->getArgument('wikipage');

        $licence = $place . '/LICENCE.md';

        $source = $this->geographicDataFilesystem();
        if (!$source->has($licence)) {
            $source->write($licence, '');
        }

        $file        = dirname($place) . '/data.geojson';
        if (!$source->has($file)) {
            $source->write($file, $this->formatGeoJson($this->emptyGeoJsonObject()));
        }


        $placename   = basename($place);
        $geojson     = json_decode($source->read($file), false, 512, JSON_THROW_ON_ERROR);

        // Make sure this feature exists.
        if (!$this->featuresInclude($geojson->features, $placename)) {
            $geojson->features[] = (object) [
                'type' => 'Feature',
                'id' => $placename,
            ];
        }

        $url = 'https://en.wikipedia.org/wiki/' . $wikipage;

        // Fetch and parse the page from wikimedia
        $html  = $this->download($url);
        $html5 = new HTML5();
        $dom   = $html5->loadHTML($html);

        // Strip the default namespace.
        // https://stackoverflow.com/questions/25484217/xpath-with-html5lib-in-php
        $namespace = $dom->documentElement->getAttributeNode('xmlns')->nodeValue;
        $dom->documentElement->removeAttributeNS($namespace, '');
        $dom->loadXML($dom->saveXML());

        $xpath = new DomXPath($dom);

        $language_nodes = $xpath->query('//a[@class="interlanguage-link-target"]');
        foreach ($language_nodes as $language_node) {
            $href = $language_node->getAttribute('href');
            if (preg_match('|^https://(.+).wikipedia.org/wiki/(.+)$|', $href, $match)) {
                $language    = $match[1];
                $translation = rawurldecode($match[2]);
                if ($translation !== $placename) {
                    foreach ($geojson->features as $feature) {
                        if ($feature->id === $placename) {
                            $feature->properties = $feature->properties ?? (object) [];
                            $feature->properties->$language = $translation;
                            $this->output->writeln('Setting ' . $language . '/' . $placename . ' to ' . $translation);
                            $source->write($file, $this->formatGeoJson($geojson));
                            break;
                        }
                    }
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param string $url
     *
     * @return string
     * @throws GuzzleException
     */
    private function download(string $url): string {
        $client = new Client();
        $result = $client->request('GET', $url);
        $bytes  = strlen($result->getBody());

        if ($result->getStatusCode() === 200) {
            $this->output->writeln('Fetched ' . $bytes . '  bytes from ' . $url);
            return $result->getBody();
        }

        $this->output->writeln('Failed to download URL. Status code ' . $result->getStatusCode());
        return '';
    }
}
