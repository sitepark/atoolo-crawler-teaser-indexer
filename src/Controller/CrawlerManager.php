<?php

/**
 * The CrawlerManager is the central orchestration point of the crawling workflow.
 *
 * Acting as the main entry point, it coordinates the complete sequence of
 * processing steps based on the Pipe and Filter architectural pattern. Each
 * step (filter) transforms the input data and passes the result forward
 * through the pipeline until the final output is produced.
 *
 * The managed steps are:
 * 1. URLCollector: Collects URLs from the base page.
 * 2. Fetcher: Retrieves the HTML content of the collected URLs. If Process storage is full starts the Parser.
 * 2. Parser: Extracts relevant teaser data from the HTML.
 * 3. Processor: Cleans and formats the extracted data.
 * 4. Indexer: Enriches and indexes the data.
 */

declare(strict_types=1);

namespace Atoolo\Crawler\Controller;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Domain\Crawler\Steps\URLCollector;
use Atoolo\Crawler\Domain\Crawler\Steps\Parser;
use Atoolo\Crawler\Domain\Crawler\Steps\Fetcher;
use Atoolo\Crawler\Domain\Crawler\Steps\Processor;
use Atoolo\Crawler\Domain\Crawler\Steps\Indexer;
use Psr\Log\LoggerInterface;

class CrawlerManager
{
    public function __construct(
        private readonly URLCollector $urlCollector,
        private readonly Fetcher $fetcher,
        private readonly Parser $parser,
        private readonly Processor $processor,
        private readonly CrawlerConfig $config,
        private readonly LoggerInterface $logger,
        private readonly Indexer $indexer
    ) {
    }

    /**
     * Starts the full crawling workflow.
     */
    public function startCrawler(): void
    {
        /** @var list<string> $urls */
        $urls = $this->executeStep(
            'URLCollector',
            fn() => $this->urlCollector->findHrefUrlsByCssSelector()
        );

        $rawTeaserStream = $this->storageHandlingFetcherParser($urls);

        $teaserStream = $this->executeStep(
            'Processor',
            fn($rawData) => $this->processor->sanitizeText($rawData),
            $rawTeaserStream
        ); //Cleans and formats the data

        /**
         *  @var array<int, array<string, mixed>> $finalTeaserStream
         */
        $finalTeaserStream = iterator_to_array($teaserStream);

        $indexerStatus = $this->indexer->doIndex($finalTeaserStream);
        if ($indexerStatus->errors == 0) {
            $this->logger->info("Status Errors [{$indexerStatus->errors}]: Crawling Prozess completed successfully.");
        } else {
            $this->logger->error("Status Errors [{$indexerStatus->errors}]: Crawling Prozess Stops by Indexer.");
        }
    }

    /**
     * @param list<string> $urls
     * @return \Generator<int, array<string, mixed>> // Hier präzise definieren!
     */
    private function storageHandlingFetcherParser($urls): iterable
    {
        $concurrency = max(1, $this->config->concurrencyPerHost());
        $urlChunks = array_chunk($urls, $concurrency);

        foreach ($urlChunks as $chunk) {
            $htmlData = $this->executeStep(
                'Fetcher',
                fn($urls) => $this->fetcher->fetchUrls($urls),
                $chunk
            );

            $teaserData = $this->executeStep(
                'Parser',
                fn($pages) => $this->parser->extractTeasers($pages),
                $htmlData
            );

            /**
             * @var array<string, mixed> $teaser
             */
            foreach ($teaserData as $teaser) {
                yield $teaser;
            }

            unset($htmlData, $teaserData);
        }
    }

    /**
     * Executes a single crawling step with logging and error handling.
     *
     * @param string   $name  The name of the step for logging purposes
     * @param callable $fn    The function representing the step
     * @param mixed    $input Optional input for the step function
     *
     * @return iterable<mixed> Die Daten des Schritts
     */
    private function executeStep(string $name, callable $fn, mixed $input = null): iterable
    {
        try {
            $result = $fn($input);

            if (is_array($result) && $result === []) {
                $this->logger->warning("[$name] Step returned no data.");
                return [];
            }

            $this->logger->info("[$name] Step initialized.");
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("[$name] Error: " . $e->getMessage(), ['exception' => $e]);
            return [];
        }
    }
}
