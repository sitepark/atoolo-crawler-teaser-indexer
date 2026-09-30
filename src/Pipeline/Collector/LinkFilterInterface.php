<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Collector;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;

/**
 * Decides which discovered links the crawler follows.
 *
 * The URLs are already canonical ({@see UrlCanonicalizer}), so rules compare
 * against one normalised form. Decorate this service to add project specific
 * rules, e.g. a host allowlist.
 */
interface LinkFilterInterface
{
    /**
     * @param list<string> $urls canonical URLs
     *
     * @return list<string> the URLs to follow, order preserved
     */
    public function filter(array $urls, PipelineConfig $config): array;
}
