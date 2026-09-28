<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Application;

use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use Psr\Log\LoggerInterface;

/**
 * Loads the site list and crawls every site in turn. Shared by the console
 * command and the scheduled message handler, which only differ in how they
 * report the result (exit code vs. nothing).
 *
 * A failing site never aborts the remaining ones. Errors while loading the
 * configuration are not caught: the caller decides how to handle them.
 */
final class SitesRunner
{
    public function __construct(
        private readonly IndexerConfigurationLoader $indexerConfigurationLoader,
        private readonly PipelineRunner $runner,
        private readonly LoggerInterface $logger,
    ) {}

    public function runAll(): SitesRunResult
    {
        $config = $this->indexerConfigurationLoader->load('atooloTeaserCrawler');

        /** @var array<string, mixed> $data */
        $data = $config->data->get();

        /** @var array<array<string, mixed>> $sites */
        $sites = $data['sp_crawling_sites'] ?? [];

        if (empty($sites)) {
            $this->logger->warning('No crawler sites configured');

            return new SitesRunResult();
        }

        $this->logger->info(sprintf('Starting crawler for %d sites', count($sites)));

        $failedSites = [];
        $invalidSites = 0;

        foreach ($sites as $site) {
            $siteKey = $site['sp_id'] ?? null;

            if (!is_string($siteKey) || '' === $siteKey) {
                $this->logger->error('Invalid site config: missing "sp_id" field');
                ++$invalidSites;
                continue;
            }

            try {
                $this->runner->run($site);
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Crawling failed for "%s": %s', $siteKey, $e->getMessage()),
                    ['exception' => $e, 'site_id' => $siteKey],
                );
                $failedSites[] = $siteKey;
            }
        }

        if ([] !== $failedSites) {
            $this->logger->error(sprintf(
                'Crawler failed for sites: %s',
                implode(', ', $failedSites),
            ));
        } elseif (0 === $invalidSites) {
            $this->logger->info('All sites crawled successfully');
        }

        return new SitesRunResult(count($sites), $failedSites, $invalidSites);
    }
}
