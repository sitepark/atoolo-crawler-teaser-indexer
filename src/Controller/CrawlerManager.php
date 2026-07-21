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
 * 2. Parser: Extracts relevant documents from the HTML.
 * 3. Processor: Cleans and formats the extracted data.
 * 4. Indexer: Enriches and indexes the data.
 */

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Controller;

use Atoolo\CrawlerIndexer\Config\CrawlerConfig;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\ExecuteStep;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Domain\Crawler\Steps\Fetcher;
use Atoolo\CrawlerIndexer\Domain\Crawler\Steps\Indexer;
use Atoolo\CrawlerIndexer\Domain\Crawler\Steps\Parser;
use Atoolo\CrawlerIndexer\Domain\Crawler\Steps\Processor;
use Atoolo\CrawlerIndexer\Domain\Crawler\Steps\URLCollector;
use Psr\Log\LoggerInterface;

class CrawlerManager
{
    public function __construct(
        private readonly URLCollector $urlCollector,
        private readonly Fetcher $fetcher,
        private readonly Parser $parser,
        private readonly Processor $processor,
        private readonly CrawlerConfig $config,
        private readonly ExecuteStep $executeStep,
        private readonly LoggerInterface $logger,
        private readonly Indexer $indexer,
    ) {}

    /**
     * Starts the full crawling workflow.
     */
    public function startCrawler(): void
    {
        $htmlPage = $this->urlCollector->collect();

        /** @var array<int, ExtractedDataInterface> $parsedDocuments */
        $parsedDocuments = [];
        foreach ($htmlPage as $html) {
            $parsedDocumentsIterator = $this->executeStep->executeStep(
                'Parser',
                fn($pages) => $this->parser->extractData($pages),
                $html,
            );
            array_push($parsedDocuments, ...iterator_to_array($parsedDocumentsIterator));
        }

        /** @var list<string> $collectedUrls */
        $collectedUrls = $htmlPage->getReturn();
        $rawParsedDocumentsStream = $this->storageHandlingFetcherParser($collectedUrls, count($parsedDocuments));

        /** @var array<int, ExtractedDataInterface> $fetchedParsedDocuments */
        $fetchedParsedDocuments = iterator_to_array($rawParsedDocumentsStream);

        $allRawParsedDocuments = array_merge($parsedDocuments, $fetchedParsedDocuments);

        /** @var ExtractedDataInterface[] $processedDocuments */
        $processedDocuments = iterator_to_array(
            $this->executeStep->executeStep(
                'Processor',
                fn($rawData) => $this->processor->sanitizeText($rawData),
                $allRawParsedDocuments,
            ),
        );

        $indexerStatus = $this->indexer->doIndex($processedDocuments);
        $this->logger->info('Indexer statusLine: ' . $indexerStatus->getStatusLine());
        if (0 == $indexerStatus->errors) {
            $this->logger->info("No Status Error [{$indexerStatus->errors}]: Crawling Prozess completed successfully.");
        } else {
            $this->logger->error("Status Errors [{$indexerStatus->errors}]: Crawling Prozess Stops by Indexing.");
        }
    }

    /**
     * @param list<string> $urls
     *
     * @return \Generator<int, ExtractedDataInterface>
     */
    private function storageHandlingFetcherParser(array $urls, int $collectedDocumentCount): \Generator
    {
        $forcedUrls = $this->config->forcedArticleUrls();
        $concurrency = max(1, $this->config->parallelRequests());

        $taggedChunks = [
            ...array_map(fn(array $c) => [true,  $c], [] !== $forcedUrls ? array_chunk($forcedUrls, $concurrency) : []),
            ...array_map(fn(array $c) => [false, $c], array_chunk($urls, $concurrency)),
        ];

        foreach ($taggedChunks as [$isForced, $chunk]) {
            if (!$isForced && $collectedDocumentCount >= $this->config->maxTeaser()) {
                continue;
            }

            $htmlDataIterator = $this->executeStep->executeStep(
                'Fetcher',
                fn($urls) => $this->fetcher->fetchUrls($urls),
                $chunk,
            );

            $htmlData = iterator_to_array($htmlDataIterator);

            $extractedDataIterator = $this->executeStep->executeStep(
                'Parser',
                fn($pages) => $this->parser->extractData($pages),
                $htmlData,
            );

            /** @var array<int, ExtractedDataInterface> $extractedData */
            $extractedData = iterator_to_array($extractedDataIterator);

            foreach ($extractedData as $document) {
                yield $document;
                ++$collectedDocumentCount;
            }

            unset($htmlData, $extractedData, $htmlDataIterator, $extractedDataIterator);
        }
    }
}
