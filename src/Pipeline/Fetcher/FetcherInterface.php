<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Fetcher;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;

interface FetcherInterface
{
    /**
     * Fetches raw HTML for multiple URLs concurrently. Pages that could not be
     * fetched or did not answer with 2xx are omitted.
     *
     * @param list<string> $urlChunk
     *
     * @return array<int, array{url: string, html: string}>
     */
    public function fetchUrls(array $urlChunk, PipelineConfig $config): array;
}
