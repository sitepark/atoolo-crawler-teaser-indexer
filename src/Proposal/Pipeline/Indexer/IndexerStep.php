<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Pipeline\Indexer;

use Atoolo\Crawler\Proposal\Config\PipelineConfig;
use Atoolo\Crawler\Proposal\Dto\IndexEntry;
use Atoolo\Crawler\Proposal\Pipeline\IndexerStepInterface;
use Atoolo\Resource\ResourceLanguage;
use Atoolo\Search\Service\Indexer\SolrIndexService;
use Psr\Log\LoggerInterface;

final class IndexerStep implements IndexerStepInterface
{
    private readonly ResourceLanguage $language;

    public function __construct(
        private readonly SolrIndexService $indexService,
        private readonly LoggerInterface $logger,
    ) {
        $this->language = ResourceLanguage::default();
    }

    /**
     * @param iterable<IndexEntry> $entries
     */
    public function index(iterable $entries, PipelineConfig $config): void
    {
        $processId = uniqid('', true);
        $source    = $config->id;
        $total     = 0;

        // count items as they flow through
        $counted = (static function () use ($entries, &$total): \Generator {
            foreach ($entries as $entry) {
                ++$total;
                yield $entry;
            }
        })();

        $successCount = $this->store($counted, $processId, $source);

        if (0 === $total) {
            $this->logger->error('[Indexer] No entries received. Aborting — live index unchanged.');

            return;
        }

        if ($successCount >= $total) {
            $this->indexService->deleteExcludingProcessId($this->language, $source, $processId);
        } else {
            $this->logger->warning(sprintf(
                '[Indexer] Skipping stale-document cleanup: only %d/%d entries stored successfully.',
                $successCount,
                $total,
            ));
        }

        try {
            $this->indexService->commit($this->language);
        } catch (\Throwable $e) {
            $this->logger->critical('[Indexer] Solr commit failed — staged changes lost', ['exception' => $e]);
            throw $e;
        }
    }

    /**
     * Streams entries into Solr (buffered, ein update() am Ende), ohne commit().
     *
     * @param \Generator<int, IndexEntry> $entries
     */
    private function store(\Generator $entries, string $processId, string $source): int
    {
        $updater      = $this->indexService->updater($this->language);
        $successCount = 0;

        foreach ($entries as $entry) {
            try {
                $doc = $updater->createDocument();
                $doc->setField('sp_source', [$source]);
                $doc->setField('id', $entry->url);
                $doc->setField('url', $entry->url);
                $doc->setField('title', $entry->title);
                $doc->setField('crawl_process_id', $processId);

                if (null !== $entry->introText) {
                    $doc->setField('sp_intro', $entry->introText);
                }

                if (null !== $entry->datetime) {
                    $doc->setField('sp_date', $entry->datetime);
                }

                $updater->addDocument($doc);
                ++$successCount;
            } catch (\Throwable $e) {
                $this->logger->error('[Indexer] Failed to build document', [
                    'url'       => $entry->url,
                    'exception' => $e,
                ]);
            }
        }

        try {
            $result = $updater->update();
        } catch (\Throwable $e) {
            $this->logger->critical('[Indexer] Solr update failed — index may be inconsistent', ['exception' => $e]);
            throw $e;
        }

        if (0 !== $result->getStatus()) {
            throw new \RuntimeException('[Indexer] Solr update returned non-zero status: ' . $result->getResponse()->getStatusMessage());
        }

        return $successCount;
    }
}
