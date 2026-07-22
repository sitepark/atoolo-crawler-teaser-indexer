<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Proposal\Pipeline;

use Atoolo\CrawlerIndexer\Proposal\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Proposal\Dto\IndexEntry;

/**
 * @throws \Throwable
 */
interface IndexerStepInterface
{
    /**
     * Persists the processed entries in the search index.
     * Removes previously indexed entries for this site that were not part of this run.
     *
     * @param iterable<IndexEntry> $entries
     */
    public function index(iterable $entries, PipelineConfig $config): void;
}
