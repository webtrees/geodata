<?php

namespace Webtrees\Geodata;

use DOMNode;
use DomXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Masterminds\HTML5;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function preg_match;
use function strlen;

class WikiFlagCommand extends AbstractBaseCommand
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
            ->setDescription('Import a flag')
            ->setHelp('Import a flag from wikimedia')
            ->setDefinition(
                new InputDefinition([
                    new InputArgument(
                        'place',
                        InputArgument::REQUIRED,
                        'Name of place (in English)'
                    ),
                    new InputArgument(
                        'flag',
                        InputArgument::REQUIRED,
                        'The URL fragment after "https://commons.wikimedia.org/wiki/File:"'
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
     * @throws GuzzleException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;

        $place = $input->getArgument('place');
        $flag  = $input->getArgument('flag');
        $url   = 'https://commons.wikimedia.org/wiki/File:' . $flag;

        if (!preg_match('|^https://commons.wikimedia.org/wiki/File:.+.svg$|', $url)) {
            $this->output->writeln('URL must be of the form https://commons.wikimedia.org/wiki/File:XXXXX.svg');

            return self::FAILURE;
        }

        // Fetch and parse the page from wikimedia
        $html  = $this->download($url);
        //$html  = file_get_contents('debug.html');
        $html5 = new HTML5();
        $dom   = $html5->loadHTML($html);

        // Strip the default namespace.
        // https://stackoverflow.com/questions/25484217/xpath-with-html5lib-in-php
        $namespace = $dom->documentElement->getAttributeNode('xmlns')->nodeValue;
        $dom->documentElement->removeAttributeNS($namespace, '');
        $dom->loadXML($dom->saveXML());

        $xpath = new DomXPath($dom);

        // From reverse-engineering the "use this file on the web" link
        $paths = [
            '//div[@id="file"]/a',
            '//div[@id="file"]/div/div/a',
            '//div[@class="fullMedia"]//a',
        ];
        $file_url = '';
        foreach ($paths as $path) {
            $node = $xpath->query($path)->item(0);
            if ($node instanceof DOMNode) {
                $file_url = $node->getAttribute('href');
                continue;
            }
        }
        $this->output->writeln('File URL: ' . $file_url);

        $svg = $this->download($file_url);
        $source->write($place . '/flag.svg', $svg);

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
