<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Proposal\Pipeline;

use Atoolo\CrawlerIndexer\Proposal\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Proposal\Dto\CrawledPage;

/**
 * @throws \Throwable
 */
interface CrawlerStepInterface
{
    /**
     * Discovers all article URLs and streams them with their fetched HTML.
     * HTML retrieved during BFS traversal is reused and freed as pages are yielded.
     *
     * @return iterable<CrawledPage>
     */
    public function crawl(PipelineConfig $config): iterable;
}
