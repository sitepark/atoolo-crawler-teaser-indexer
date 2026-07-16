<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Dto;

/**
 * Output of CrawlerStepInterface, input to ParserStepInterface.
 * Holds the raw HTML of one discovered page.
 */
final readonly class CrawledPage
{
    public function __construct(
        public string $url,
        public string $html,
    ) {}
}
