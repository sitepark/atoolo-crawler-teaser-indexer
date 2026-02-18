<?php

namespace Atoolo\Crawler\Application;

/**
 * Message used by the Symfony Scheduler and Messenger
 * to trigger a full crawl and index process.
 */
final class StartCrawlerMessage
{
    public function __construct(public readonly string $siteKey)
    {
    }
}
