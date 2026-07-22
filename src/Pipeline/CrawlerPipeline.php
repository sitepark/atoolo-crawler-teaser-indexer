<?php

/**
 * The CrawlerPipeline is the central orchestration point of the crawling workflow.
 *
 * Acting as the main entry point, it coordinates the complete sequence of
 * processing steps based on the Pipe and Filter architectural pattern. Each
 * step (filter) transforms the input data and passes the result forward
 * through the pipeline until the final output is produced.
 *
 * The managed steps are:
 * 1. URLCollector: Crawls the start URLs and streams every fetched page (each fetched exactly once).
 * 2. Parser: Extracts relevant documents from the streamed HTML.
 * 3. Processor: Cleans and formats the extracted data.
 * 4. Indexer: Enriches and indexes the data.
 */

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline;

use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Pipeline\Indexer\Indexer;
use Atoolo\CrawlerIndexer\Pipeline\Parser\Parser;
use Atoolo\CrawlerIndexer\Pipeline\Processor\Processor;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLCollector;
use Psr\Log\LoggerInterface;

class CrawlerPipeline
{
    public function __construct(
        private readonly URLCollector $urlCollector,
        private readonly Parser $parser,
        private readonly Processor $processor,
        private readonly ExecuteStep $executeStep,
        private readonly LoggerInterface $logger,
        private readonly Indexer $indexer,
    ) {}

    /**
     * Starts the full crawling workflow.
     *
     * The URLCollector streams every fetched page as a chunk; each chunk is
     * parsed as it arrives. The collected documents are then sanitized and
     * indexed. There is no separate fetch pass - every page is fetched
     * exactly once by the collector.
     */
    public function startCrawler(): void
    {
        $htmlChunks = $this->urlCollector->collect();
        /** @var array<int, ExtractedDataInterface> $rawParsedDocuments */
        $rawParsedDocuments = [];
        foreach ($htmlChunks as $htmlChunk) {
            $parsedDocumentsIterator = $this->executeStep->executeStep(
                'Parser',
                fn($pages) => $this->parser->extractData($pages),
                $htmlChunk,
            );
            array_push($rawParsedDocuments, ...iterator_to_array($parsedDocumentsIterator));
        }

        /** @var ExtractedDataInterface[] $processedDocuments */
        $processedDocuments = iterator_to_array(
            $this->executeStep->executeStep(
                'Processor',
                fn($rawData) => $this->processor->sanitizeText($rawData),
                $rawParsedDocuments,
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
}
