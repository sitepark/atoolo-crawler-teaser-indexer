<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Proposal\Pipeline\Parser;

use Atoolo\CrawlerIndexer\Proposal\Config\ContentScoringConfig;
use Atoolo\CrawlerIndexer\Proposal\Dto\IndexEntry;

interface RelevanceEvaluatorInterface
{
    /**
     * @param string $bodyText plain text extracted from the page body — not raw HTML
     */
    public function relevant(IndexEntry $entry, string $bodyText, ContentScoringConfig $config): bool;
}
