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
use Atoolo\CrawlerIndexer\Exception\StepExecution;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLCollectorInterface;
use Atoolo\CrawlerIndexer\Pipeline\Indexer\IndexerInterface;
use Atoolo\CrawlerIndexer\Pipeline\Parser\ParserInterface;
use Atoolo\CrawlerIndexer\Pipeline\Processor\ProcessorInterface;
use Psr\Log\LoggerInterface;

class CrawlerPipeline
{
    public function __construct(
        private readonly URLCollectorInterface $urlCollector,
        private readonly ParserInterface $parser,
        private readonly ProcessorInterface $processor,
        private readonly IndexerInterface $indexer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Starts the full crawling workflow.
     *
     * The URLCollector streams every fetched page as a chunk; each chunk is
     * parsed as it arrives. The collected documents are then sanitized and
     * indexed. There is no separate fetch pass - every page is fetched
     * exactly once by the collector.
     *
     * Each step is handled explicitly (empty/error/logging) rather than
     * through a generic wrapper, because the steps are not interchangeable.
     */
    public function startCrawler(): void
    {
        // Mark the start of the run so the indexer's reported duration spans
        // the whole crawl (crawling + parsing + indexing), not just indexing.
        $this->indexer->prepare('Crawler run started');

        /** @var array<int, ExtractedDataInterface> $rawDocuments */
        $rawDocuments = [];
        foreach ($this->urlCollector->collect() as $htmlChunk) {
            foreach ($this->parse($htmlChunk) as $document) {
                $rawDocuments[] = $document;
            }
        }

        $this->index($this->process($rawDocuments));
    }

    /**
     * @param array<int, array{url: string, html: string}> $htmlChunk
     *
     * @return \Generator<int,ExtractedDataInterface[]>
     */
    private function parse(array $htmlChunk): \Generator
    {
        try {
            $documents = $this->parser->extractData($htmlChunk);
        } catch (\Throwable $e) {
            $this->logger->error('[Parser] Error: ' . $e->getMessage(), ['exception' => $e]);
            throw new StepExecution('Parser', $e->getMessage(), $e);
        }

        if ([] === $documents) {
            $this->logger->warning('[Parser] Step returned no data.');
        }

        yield from $documents;
    }

    /**
     * @param array<int, ExtractedDataInterface> $rawDocuments
     *
     * @return ExtractedDataInterface[]
     */
    private function process(array $rawDocuments): array
    {
        try {
            $sanitized = $this->processor->sanitizeText($rawDocuments);
            $documents = is_array($sanitized) ? $sanitized : iterator_to_array($sanitized);
        } catch (\Throwable $e) {
            $this->logger->error('[Processor] Error: ' . $e->getMessage(), ['exception' => $e]);
            throw new StepExecution('Processor', $e->getMessage(), $e);
        }

        if ([] === $documents) {
            $this->logger->warning('[Processor] Step returned no data.');
        }

        return array_values($documents);
    }

    /**
     * @param ExtractedDataInterface[] $processedDocuments
     */
    private function index(array $processedDocuments): void
    {
        $indexerStatus = $this->indexer->doIndex($processedDocuments);
        $this->logger->info('Indexer statusLine: ' . $indexerStatus->getStatusLine());
        if (0 == $indexerStatus->errors) {
            $this->logger->info("No Status Error [{$indexerStatus->errors}]: Crawling Prozess completed successfully.");
        } else {
            $this->logger->error("Status Errors [{$indexerStatus->errors}]: Crawling Prozess Stops by Indexing.");
        }
    }
}
