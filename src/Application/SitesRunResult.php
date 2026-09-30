<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Application;

/**
 * Outcome of one {@see SitesRunner::runAll()} pass over all configured sites.
 */
final class SitesRunResult
{
    /**
     * @param list<string> $failedSites sp_id of every site whose crawl threw
     */
    public function __construct(
        public readonly int $total = 0,
        public readonly array $failedSites = [],
        public readonly int $invalidSites = 0,
    ) {}

    public function isSuccessful(): bool
    {
        return [] === $this->failedSites && 0 === $this->invalidSites;
    }
}
