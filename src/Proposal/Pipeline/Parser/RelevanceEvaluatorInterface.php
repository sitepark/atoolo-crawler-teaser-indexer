<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Pipeline\Parser;

use Atoolo\Crawler\Proposal\Config\ContentScoringConfig;
use Atoolo\Crawler\Proposal\Dto\IndexEntry;

interface RelevanceEvaluatorInterface
{
    /**
     * @param string $bodyText Plain text extracted from the page body — not raw HTML.
     */
    public function relevant(IndexEntry $entry, string $bodyText, ContentScoringConfig $config): bool;
}
