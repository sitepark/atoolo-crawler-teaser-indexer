<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Pipeline;

use Atoolo\Crawler\Proposal\Config\PipelineConfig;
use Atoolo\Crawler\Proposal\Dto\CrawledPage;

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
