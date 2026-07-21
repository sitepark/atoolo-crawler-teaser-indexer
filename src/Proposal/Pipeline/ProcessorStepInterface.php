<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Proposal\Pipeline;

use Atoolo\CrawlerIndexer\Proposal\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Proposal\Dto\IndexEntry;

/**
 * @throws \Throwable
 */
interface ProcessorStepInterface
{
    /**
     * Sanitizes and normalizes the extracted entries before indexing.
     * Entries that cannot be sanitized may be dropped.
     *
     * @param iterable<IndexEntry> $entries
     *
     * @return iterable<IndexEntry>
     */
    public function process(iterable $entries, PipelineConfig $config): iterable;
}
