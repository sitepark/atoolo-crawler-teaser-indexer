<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Pipeline;

use Atoolo\Crawler\Proposal\Config\PipelineConfig;
use Atoolo\Crawler\Proposal\Dto\CrawledPage;
use Atoolo\Crawler\Proposal\Dto\IndexEntry;

/**
 * @throws \Throwable
 */
interface ParserStepInterface
{
    /**
     * Extracts structured entries from raw HTML pages.
     * Pages that yield no extractable data are silently skipped.
     *
     * @param iterable<CrawledPage> $pages
     * @return iterable<IndexEntry>
     */
    public function parse(iterable $pages, PipelineConfig $config): iterable;
}
