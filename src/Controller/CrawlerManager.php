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
use Atoolo\Crawler\Exception\StepExecution;
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
        private readonly Indexer $indexer,
    ) {}

    /**
     * Starts the full crawling workflow.
     */
    public function startCrawler(): void
    {
        /** @var \Iterator<int, string> $urlsIterator */
        $urlsIterator = $this->executeStep(
            'URLCollector',
            fn() => $this->urlCollector->findHrefUrlsByCssSelector(),
        );

        $urls = iterator_to_array($urlsIterator);

        $rawTeaserStream = $this->storageHandlingFetcherParser($urls);

        //Cleans and formats the data
        $teaserStream = $this->executeStep(
            'Processor',
            fn($rawData) => $this->processor->sanitizeText($rawData),
            $rawTeaserStream,
        );

        /**
         *  @var array<int, array<string, mixed>> $finalTeaserStream
         */
        $finalTeaserStream = iterator_to_array($teaserStream);

        $indexerStatus = $this->indexer->doIndex($finalTeaserStream);
        $this->logger->info("Indexer statusLine: " . $indexerStatus->getStatusLine());
        if ($indexerStatus->errors == 0) {
            $this->logger->info("No Status Error [{$indexerStatus->errors}]: Crawling Prozess completed successfully.");
        } else {
            $this->logger->error("Status Errors [{$indexerStatus->errors}]: Crawling Prozess Stops by Indexing.");
        }
    }

    /**
     * @param list<string> $urls
     * @return \Generator<int, array<string, mixed>>
     */
    private function storageHandlingFetcherParser($urls): iterable
    {
        $concurrency = max(1, $this->config->parallelRequests());
        $urlChunks = array_chunk($urls, $concurrency);

        foreach ($urlChunks as $chunk) {
            $htmlDataIterator = $this->executeStep(
                'Fetcher',
                fn($urls) => $this->fetcher->fetchUrls($urls),
                $chunk,
            );

            $htmlData = iterator_to_array($htmlDataIterator);

            $teaserDataIterator = $this->executeStep(
                'Parser',
                fn($pages) => $this->parser->extractTeasers(
                    is_array($pages) ? $pages : iterator_to_array($pages),
                ),
                $htmlData,
            );

            $teaserData = iterator_to_array($teaserDataIterator);

            /**
             * @var array<string, mixed> $teaser
             */
            foreach ($teaserData as $teaser) {
                yield $teaser;
            }

            unset($htmlData, $teaserData, $htmlDataIterator, $teaserDataIterator);
        }
    }

    /**
     * Executes a single crawling step with logging and error handling.
     *
     * @param string   $name  The name of the step for logging purposes
     * @param callable $fn    The function representing the step
     * @param mixed    $input Optional input for the step function
     *
     * @return \Iterator<mixed>
     */
    private function executeStep(string $name, callable $fn, mixed $input = null): \Iterator
    {
        try {
            $result = $fn($input);

            if (is_array($result) && $result === []) {
                $this->logger->warning("[$name] Step returned no data.");
                return new \ArrayIterator([]);
            }

            $this->logger->info("[$name] Step initialized.");

            if (is_array($result)) {
                return new \ArrayIterator($result);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("[$name] Error: " . $e->getMessage(), ['exception' => $e]);
            throw new StepExecution($name, $e->getMessage(), $e);
        }
    }
}
