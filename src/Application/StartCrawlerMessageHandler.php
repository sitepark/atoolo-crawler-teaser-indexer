<?php

namespace Atoolo\Crawler\Application;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StartCrawlerMessageHandler
{
    public function __construct(
        private readonly CrawlSiteRunner $runner
    ) {
    }

    public function __invoke(StartCrawlerMessage $message): void
    {
        $this->runner->run($message->getSite());
    }
}
