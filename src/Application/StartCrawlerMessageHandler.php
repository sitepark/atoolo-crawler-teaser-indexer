<?php

namespace Atoolo\Crawler\Application;

use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StartCrawlerMessageHandler
{
    public function __construct(
        private readonly CrawlSiteRunner $runner,
        private readonly IndexerConfigurationLoader $indexerConfigurationLoader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(StartCrawlerMessage $message): void
    {
        $config = $this->indexerConfigurationLoader->load("atooloTeaserCrawler");

        /** @var array<string, mixed> $data */
        $data = $config->data->get();

        /** @var array<array<string, mixed>> $sites */
        $sites = $data["crawling_sites"] ?? [];

        if (empty($sites)) {
            $this->logger->warning('No crawler sites configured.');
            return;
        }
        foreach ($sites as $site) {
            if ($this->isValidSite($site)) {
                $this->runner->run($site);
            }
        }
    }
    /**
     * @param array<string, mixed> $site
     */
    private function isValidSite(array $site): bool
    {
        if (empty($site['id'] ?? null)) {
            $this->logger->error('Invalid site config: missing "id" field.');
            return false;
        }

        return true;
    }
}
