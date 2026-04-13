<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Application;

use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Controller\CrawlerManager;
use Psr\Log\LoggerInterface;

final class CrawlSiteRunner
{
    public function __construct(
        private readonly CrawlerConfigContext $configContext,
        private readonly CrawlerManager $crawlerManager,
        private readonly LoggerInterface $logger,
    ) {
    }
    /**
     * @param array<string, mixed> $site
     */
    public function run(array $site): void
    {
        $this->configContext->set($site);

        try {
            $this->crawlerManager->startCrawler();
        } catch (\Throwable $e) {
            $this->logger->error('[Crawler] Failed site', [
                'exception' => $e,
            ]);
            throw $e;
        } finally {
            $this->configContext->reset();
        }
    }
}
