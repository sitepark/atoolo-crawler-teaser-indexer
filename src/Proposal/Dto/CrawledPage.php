<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Proposal\Dto;

/**
 * Output of CrawlerStepInterface, input to ParserStepInterface.
 * Holds the raw HTML of one discovered page.
 */
final class CrawledPage
{
    public function __construct(
        public readonly string $url,
        public readonly string $html,
    ) {}
}
