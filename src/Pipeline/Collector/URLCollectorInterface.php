<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Collector;

interface URLCollectorInterface
{
    /**
     * Crawls the configured start URLs and streams every fetched page as a
     * chunk of `{url, html}` entries.
     *
     * @return iterable<int, array<int, array{url: string, html: string}>>
     */
    public function collect(): iterable;
}
