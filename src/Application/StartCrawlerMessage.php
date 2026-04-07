<?php

namespace Atoolo\Crawler\Application;

/**
 * Message used by the Symfony Scheduler and Messenger
 * to trigger a full crawl and index process.
 */
final class StartCrawlerMessage
{
    /**
     * @param array<string, mixed> $site
     */
    public function __construct(
        private readonly array $site
    ) {
    }
    /**
     * @return array<string, mixed>
     */
    public function getSite(): array
    {
        return $this->site;
    }
}
