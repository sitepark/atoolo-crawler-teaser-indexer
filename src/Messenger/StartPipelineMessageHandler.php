<?php

namespace Atoolo\CrawlerIndexer\Messenger;

use Atoolo\CrawlerIndexer\Application\SitesRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StartPipelineMessageHandler
{
    public function __construct(
        private readonly SitesRunner $sitesRunner,
    ) {}

    /**
     * Configuration load errors propagate on purpose, so Messenger can apply
     * its retry / failure transport handling.
     */
    public function __invoke(StartPipelineMessage $message): void
    {
        $this->sitesRunner->runAll();
    }
}
