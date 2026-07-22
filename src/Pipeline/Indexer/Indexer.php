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

class Indexer implements \Atoolo\Search\Indexer
{
    private string $source = '';

    public function __construct(
        private IndexerProgressHandler $progressHandler,
        private SolrIndexService $indexService,
        private readonly PipelineConfig $config,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Main indexing logic: transforms items into Solr documents.
     *
     * @param ExtractedDataInterface[] $finalDocuments
     */
    public function doIndex(array $finalDocuments): IndexerStatus
    {
        $language = ResourceLanguage::default();
        $updater = $this->indexService->updater($language);

        $this->progressHandler->start(count($finalDocuments));

        $processId = uniqid('', true);
        $successCount = 0;
        $this->source = $this->config->id();
        foreach ($finalDocuments as $finalDocument) {
            try {
                $document = $updater->createDocument();

                $document->setField('id', $finalDocument->getUrl());
                $document->setField('title', $finalDocument->getTitle());

                if (!empty($finalDocument->getIntroText()) && $this->config->introTextPresent()) {
                    $intro = $finalDocument->getIntroText();
                    $document->setField('sp_intro', $intro);
                }

                if (!empty($finalDocument->getDate()) && $this->config->dateTimePresent()) {
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

                $document->setField('sp_category', $this->config->categoriesId());
                $document->setField('sp_category_path', $this->config->categoriesPathId());
                $document->setField('url', $finalDocument->getUrl());
                $document->setField('sp_objecttype', $this->source);
                $document->setField('crawl_process_id', $processId);
                $document->setField('sp_source', [$this->source]);

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

        if ($successCount <= $this->config->cleanupThreshold()) {
            $this->logger->critical('Cleanup threshold not met. Aborting.', [
                'successCount' => $successCount,
                'threshold' => $this->config->cleanupThreshold(),
            ]);
            throw new ThresholdNotMetException($successCount, $this->config->cleanupThreshold());
        }

        $this->indexService->deleteExcludingProcessId(
            $language,
            $this->source,
            $processId,
        );

        $this->indexService->commit($language);

        $this->progressHandler->finish();

        return $this->progressHandler->getStatus();
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

    public function getSource(): string
    {
        return $this->source;
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
