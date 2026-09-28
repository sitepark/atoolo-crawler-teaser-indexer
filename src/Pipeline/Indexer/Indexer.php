<?php

/**
 * Implementation of \Atoolo\Search\Indexer for RCE-based data sources.
 * Only the indexing flow is used; other interface methods are no-ops.
 */

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Indexer;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Exception\ThresholdNotMetException;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Search\Dto\Indexer\IndexerStatus;
use Atoolo\Search\Service\Indexer\IndexerProgressHandler;
use Atoolo\Search\Service\Indexer\SolrIndexService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Indexer implements \Atoolo\Search\Indexer, IndexerInterface
{
    public function __construct(
        private IndexerProgressHandler $progressHandler,
        private SolrIndexService $indexService,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Marks the start of the crawl run so the reported duration spans the
     * whole process. doIndex()'s start() reuses this start time (see
     * IndexerProgressState::start()).
     */
    public function prepare(string $message): void
    {
        $this->progressHandler->prepare($message);
    }

    /**
     * Main indexing logic: transforms items into Solr documents.
     *
     * @param iterable<int, ExtractedDataInterface> $finalDocuments
     */
    public function doIndex(iterable $finalDocuments, PipelineConfig $config): IndexerStatus
    {
        // Local, not a property: the indexer is shared across sites.
        $source = $config->id();

        // The one place the lazy pipeline is buffered: start() needs the total
        // up front, and the Solr updater collects all documents until update()
        // anyway. A page can produce several documents (1:N); content-duplicates
        // (same title + intro + date) are dropped on the way.
        $finalDocuments = $this->deduplicate($finalDocuments);

        $language = ResourceLanguage::default();
        $updater = $this->indexService->updater($language);

        $this->progressHandler->start(count($finalDocuments));

        $processId = uniqid('', true);
        $successCount = 0;
        foreach ($finalDocuments as $finalDocument) {
            try {
                $document = $updater->createDocument();

                $document->setField('id', $this->buildDocumentId($finalDocument, $source));
                $document->setField('title', $finalDocument->getTitle());

                if (!empty($finalDocument->getIntroText()) && $config->introTextPresent()) {
                    $intro = $finalDocument->getIntroText();
                    $document->setField('sp_intro', $intro);
                }

                if (!empty($finalDocument->getDate()) && $config->dateTimePresent()) {
                    try {
                        $date = $finalDocument->getDate();
                        $dateValue = $date;

                        $document->setField('sp_date', $dateValue);
                    } catch (\Exception $e) {
                        $this->logger->warning('[Indexer] Invalid date format', [
                            'date' => $finalDocument->getDate(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $document->setField('sp_category', $config->categoriesId());
                $document->setField('sp_category_path', $config->categoriesPathId());
                $document->setField('url', $finalDocument->getUrl());
                $document->setField('sp_objecttype', $source);
                $document->setField('crawl_process_id', $processId);
                $document->setField('sp_source', [$source]);

                $updater->addDocument($document);
                $this->progressHandler->advance(1);
                ++$successCount;
            } catch (\Throwable $exception) {
                $this->logger->error('Indexing failed', [
                    'document' => $finalDocument,
                    'exception' => $exception,
                ]);
                $this->progressHandler->error($exception);
            }
        }

        try {
            $result = $updater->update();
        } catch (\Throwable $e) {
            $this->logger->critical('Solr update failed - Solr not reachable?', [
                'exception' => $e,
            ]);
            throw $e;
        }

        if (0 !== $result->getStatus()) {
            $this->progressHandler->error(
                new \Exception($result->getResponse()->getStatusMessage()),
            );
        }

        if ($successCount <= $config->cleanupThreshold()) {
            $this->logger->critical('Cleanup threshold not met. Aborting.', [
                'successCount' => $successCount,
                'threshold' => $config->cleanupThreshold(),
            ]);
            throw new ThresholdNotMetException($successCount, $config->cleanupThreshold());
        }

        $this->indexService->deleteExcludingProcessId(
            $language,
            $source,
            $processId,
        );

        $this->indexService->commit($language);

        $this->progressHandler->finish();

        return $this->progressHandler->getStatus();
    }

    /**
     * Removes documents that are redundant by content (same title, intro and
     * date), keeping the first occurrence. Distinct documents from the same
     * page are kept.
     *
     * @param iterable<int, ExtractedDataInterface> $documents
     *
     * @return list<ExtractedDataInterface>
     */
    private function deduplicate(iterable $documents): array
    {
        $seen = [];
        $unique = [];
        foreach ($documents as $document) {
            $key = $this->signature($document);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $document;
        }

        return $unique;
    }

    /**
     * Builds a stable, source-scoped document id from its content, so several
     * documents from one page get distinct ids while identical content maps to
     * the same id (id is no longer the URL).
     */
    private function buildDocumentId(ExtractedDataInterface $document, string $source): string
    {
        return sha1($source . "\0" . $this->signature($document));
    }

    private function signature(ExtractedDataInterface $document): string
    {
        return implode("\0", [
            $document->getTitle(),
            $document->getIntroText() ?? '',
            $document->getDate()?->format(\DATE_ATOM) ?? '',
        ]);
    }

    // -------------------------------------------------------------------------
    // Interface boilerplate (intentionally unused)
    // -------------------------------------------------------------------------

    public function index(): IndexerStatus
    {
        return IndexerStatus::empty();
    }

    public function abort(): void
    {
        // No-op: not required for this indexer
    }

    public function enabled(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'rce-indexer';
    }

    /**
     * The source is a per-site value passed to doIndex(); the shared indexer
     * has none of its own.
     */
    public function getSource(): string
    {
        return '';
    }

    public function getProgressHandler(): IndexerProgressHandler
    {
        return $this->progressHandler;
    }

    public function setProgressHandler(IndexerProgressHandler $progressHandler): void
    {
        // No-op: handler is injected via constructor
    }

    /**
     * @param string[] $idList
     */
    public function remove(array $idList): void
    {
        // No-op: document removal is handled elsewhere
    }
}
