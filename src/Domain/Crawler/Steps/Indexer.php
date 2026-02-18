<?php

/**
 * Implementation of \Atoolo\Search\Indexer for RCE-based data sources.
 * Only the indexing flow is used; other interface methods are no-ops.
 */

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Steps;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Search\Dto\Indexer\IndexerStatus;
use Atoolo\Search\Service\Indexer\IndexerProgressHandler;
use Atoolo\Search\Service\Indexer\SolrIndexService;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Indexer implements \Atoolo\Search\Indexer
{
    private string $source;
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
     * @param array<int, array<string, mixed>> $items
     */
    public function doIndex(array $items): IndexerStatus
    {
        $language = ResourceLanguage::default();
        $updater = $this->indexService->updater($language);

        $this->progressHandler->start(count($items));

        $processId = uniqid('', true);
        $successCount = 0;
        $this->source = $this->config->id();

        foreach ($items as $item) {
            try {
                $document = $updater->createDocument();
                $document->id = $item['url'];
                $document->title = $item['title'];
                if (!empty($item['introText']) && $this->config->introTextPresent()) {
                    $document->sp_intro = (string) $item['introText'];
                }

                if (!empty($item['date']) && $this->config->dateTimePresent()) {
                    try {
                        $document->sp_date = new \DateTimeImmutable((string) $item['date'], new \DateTimeZone('UTC'));
                    } catch (\Exception $e) {
                        $this->logger->warning('[Indexer] Invalid date format', [
                            'date' => $item['date'],
                            'url'  => $item['url'] ?? null,
                        ]);
                    }
                }

                $document->url = $item['url'];
                $document->sp_objecttype = $this->source;
                $document->crawl_process_id = $processId;
                $document->sp_source = [$this->source];

                $updater->addDocument($document);
                $this->progressHandler->advance(1);
                $successCount++;
            } catch (\Throwable $exception) {
                $this->logger->error('Indexing failed', [
                    'item' => $item,
                    'exception' => $exception,
                ]);
                $this->progressHandler->error($exception);
            }
        }

        $result = $updater->update();
        if ($result->getStatus() !== 0) {
            $this->progressHandler->error(
                new \Exception($result->getResponse()->getStatusMessage())
            );
        }
        if ($successCount >= count($items)) {
            $this->indexService->deleteExcludingProcessId(
                $language,
                $this->source,
                $processId,
            );
        }

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
