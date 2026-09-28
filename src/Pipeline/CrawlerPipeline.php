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

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Exception\IndexingErrorsException;
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
     * The steps are chained lazily: the URLCollector streams every fetched
     * page as a chunk, each chunk is parsed and processed as it arrives, and
     * the work only happens while the Indexer consumes the chain. There is no
     * separate fetch pass - every page is fetched exactly once by the
     * collector - and no intermediate list of documents.
     *
     * Each step is handled explicitly (empty/error/logging) rather than
     * through a generic wrapper, because the steps are not interchangeable.
     *
     * The pipeline and its steps are shared services; everything site specific
     * travels in $config. State the steps keep within one run (throttle
     * timestamps, robots.txt cache) is cleared between runs by Symfony's
     * `kernel.reset` (the steps implement ResetInterface).
     */
    public function run(PipelineConfig $config): void
    {
        // Mark the start of the run so the indexer's reported duration spans
        // the whole crawl (crawling + parsing + indexing), not just indexing.
        $this->indexer->prepare('Crawler run started');

        $pageChunks = $this->collect($config);
        $documents = $this->parse($pageChunks, $config);
        $processed = $this->process($documents, $config);

        $this->index($processed, $config);
    }

    /*
     * Error guards: a generator only runs (and can fail) while iterated, so
     * each guard wraps the iteration of its step. Because the chain is lazy,
     * an upstream failure travels through the downstream guards - a
     * StepExecution therefore passes unchanged, so the failure keeps the name
     * of the step it came from. Per-page/per-document errors are handled
     * inside the steps; these catch step-level failures.
     */

    /**
     * @return \Generator<int, array<int, array{url: string, html: string}>>
     */
    private function collect(PipelineConfig $config): \Generator
    {
        try {
            yield from $this->urlCollector->collect($config);
        } catch (\Throwable $e) {
            throw $this->stepFailed('URLCollector', $e);
        }
    }

    /**
     * @param iterable<int, array<int, array{url: string, html: string}>> $pageChunks
     *
     * @return \Generator<int, ExtractedDataInterface>
     */
    private function parse(iterable $pageChunks, PipelineConfig $config): \Generator
    {
        foreach ($pageChunks as $pageChunk) {
            try {
                yield from $this->parser->extractData($pageChunk, $config);
            } catch (\Throwable $e) {
                throw $this->stepFailed('Parser', $e);
            }
        }
    }

    /**
     * @param iterable<int, ExtractedDataInterface> $documents
     *
     * @return \Generator<int, ExtractedDataInterface>
     */
    private function process(iterable $documents, PipelineConfig $config): \Generator
    {
        $count = 0;
        try {
            foreach ($this->processor->sanitizeText($documents, $config) as $document) {
                ++$count;
                yield $document;
            }
        } catch (\Throwable $e) {
            throw $this->stepFailed('Processor', $e);
        }

        if (0 === $count) {
            $this->logger->warning('[Processor] Step returned no data.');
        }
    }

    /**
     * Wraps a step failure - unless it already is one from an upstream step.
     */
    private function stepFailed(string $step, \Throwable $e): StepExecution
    {
        if ($e instanceof StepExecution) {
            return $e;
        }

        $this->logger->error(sprintf('[%s] Error: %s', $step, $e->getMessage()), ['exception' => $e]);

        return new StepExecution($step, $e->getMessage(), $e);
    }

    /**
     * Indexer errors are raised rather than only logged, so the site is
     * counted as failed by the caller. The error itself is logged once there,
     * together with the site id.
     *
     * @param iterable<int, ExtractedDataInterface> $processedDocuments
     *
     * @throws IndexingErrorsException when the indexer reported errors
     */
    private function index(iterable $processedDocuments, PipelineConfig $config): void
    {
        $indexerStatus = $this->indexer->doIndex($processedDocuments, $config);
        $statusLine = $indexerStatus->getStatusLine();
        $this->logger->info('Indexer statusLine: ' . $statusLine);

        if ($indexerStatus->errors > 0) {
            throw new IndexingErrorsException($indexerStatus->errors, $statusLine);
        }

        $this->logger->info('Crawling process completed successfully.');
    }
}
