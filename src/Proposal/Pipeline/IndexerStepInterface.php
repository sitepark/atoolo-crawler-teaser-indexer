<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Pipeline;

use Atoolo\Crawler\Proposal\Config\PipelineConfig;
use Atoolo\Crawler\Proposal\Dto\IndexEntry;

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
