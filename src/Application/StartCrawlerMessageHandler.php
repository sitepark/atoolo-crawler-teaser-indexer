<?php

namespace Atoolo\Crawler\Application;

use Atoolo\Crawler\Application\StartCrawlerMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StartCrawlerMessageHandler
{
    public function __construct(private readonly CrawlSiteRunner $runner)
    {
    }

    public function __invoke(StartCrawlerMessage $message): void
    {
        $this->runner->run($message->siteKey);
    }
}
