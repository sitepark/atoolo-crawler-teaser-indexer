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
    ) {}

    /**
     * @param array<string, mixed> $site
     */
    public function run(array $site): void
    {
        $this->configContext->set($site);
        /** @var string $siteKey */
        $siteKey = $site['sp_id'] ?? null;

        if (!is_string($siteKey) || '' === $siteKey) {
            $this->logger->error('Invalid site config: missing "sp_id" field.');
            throw new \InvalidArgumentException('Site config is missing required field "sp_id".');
        }

        try {
            $this->logger->info(sprintf('Processing site: %s', $siteKey));
            $this->crawlerManager->startCrawler();
            $this->logger->info(sprintf('Successfully crawled: %s', $siteKey));
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
