<?php

/**
 * Implementation of \Atoolo\Search\Indexer for RCE-based data sources.
 * Only the indexing flow is used; other interface methods are no-ops.
 */

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Steps;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Domain\Crawler\Services\TeaserDataInterface;
use Atoolo\Crawler\Exception\ThresholdNotMetException;
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
        private readonly CrawlerConfig $config,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Main indexing logic: transforms items into Solr documents.
     *
     * @param TeaserDataInterface[] $finalTeaserData
     */
    public function doIndex(array $finalTeaserData): IndexerStatus
    {
        $language = ResourceLanguage::default();
        $updater = $this->indexService->updater($language);

        $this->progressHandler->start(count($finalTeaserData));

        $processId = uniqid('', true);
        $successCount = 0;
        $this->source = $this->config->id();
        foreach ($finalTeaserData as $teaser) {
            try {
                $document = $updater->createDocument();

                $document->setField('id', $teaser->getUrl());
                $document->setField('title', $teaser->getTitle());

                if (!empty($teaser['introText']) && $this->config->introTextPresent()) {
                    $intro = is_string($teaser->getIntroText()) ? $teaser->getIntroText() : '';
                    $document->setField('sp_intro', $intro);
                }

                if (!empty($teaser->getDate()) && $this->config->dateTimePresent()) {
                    try {
                        $date = $teaser->getDate();
                        if ($date instanceof \DateTimeInterface) {
                            $dateValue = $date;
                        } elseif (is_scalar($date)) {
                            $dateValue = new \DateTimeImmutable((string) $date, new \DateTimeZone('UTC'));
                        } else {
                            throw new \InvalidArgumentException('Invalid date type');
                        }

                        $document->setField('sp_date', $dateValue);
                    } catch (\Exception $e) {
                        $this->logger->warning('[Indexer] Invalid date format', [
                            'date' => $teaser->getDate(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $document->setField('sp_category', $this->config->categoriesId());
                $document->setField('sp_category_path', $this->config->categoriesPathId());
                $document->setField('url', $teaser->getUrl());
                $document->setField('sp_objecttype', $this->source);
                $document->setField('crawl_process_id', $processId);
                $document->setField('sp_source', [$this->source]);

                $updater->addDocument($document);
                $this->progressHandler->advance(1);
                ++$successCount;
            } catch (\Throwable $exception) {
                $this->logger->error('Indexing failed', [
                    'teaser' => $teaser,
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
                new \Exception($result->getResponse()->getStatusMessage())
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
