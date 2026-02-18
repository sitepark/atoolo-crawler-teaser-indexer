<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Application;

use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\SiteConfigLoader;
use Atoolo\Crawler\Controller\CrawlerManager;
use Psr\Log\LoggerInterface;

final class CrawlSiteRunner
{
    public function __construct(
        private readonly SiteConfigLoader $siteConfigLoader,
        private readonly CrawlerConfigContext $configContext,
        private readonly CrawlerManager $crawlerManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(string $siteKey): void
    {
        $this->logger->info(sprintf('[Crawler] Starting site: %s', $siteKey));

        $params = $this->siteConfigLoader->load($siteKey);
        $this->configContext->set($params);

        try {
            $this->crawlerManager->startCrawler();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Crawler] Failed site: %s', $siteKey), [
                'exception' => $e,
            ]);
            throw $e;
        } finally {
            $this->configContext->reset();
        }

        $this->logger->info(sprintf('[Crawler] Finished site: %s', $siteKey));
    }
}
