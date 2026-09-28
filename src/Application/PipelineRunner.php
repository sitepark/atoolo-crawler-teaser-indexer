<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Application;

use Atoolo\CrawlerIndexer\Config\PipelineConfigFactory;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipelineFactory;
use Psr\Log\LoggerInterface;

final class PipelineRunner
{
    public function __construct(
        private readonly PipelineConfigFactory $configFactory,
        private readonly CrawlerPipelineFactory $pipelineFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Failures are not logged here but propagate to the caller
     * ({@see SitesRunner}), which logs them once together with the site id.
     *
     * @param array<string, mixed> $site
     */
    public function run(array $site): void
    {
        $config = $this->configFactory->create($site);
        $siteKey = $config->id();

        $this->logger->info(sprintf('Processing site: %s', $siteKey));
        $this->pipelineFactory->create($config)->startCrawler();
        $this->logger->info(sprintf('Successfully crawled: %s', $siteKey));
    }
}
