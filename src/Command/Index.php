<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Command;

use Atoolo\Crawler\Application\CrawlSiteRunner;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'crawler:index',
    description: 'Run crawler for all configured sites sequentially (same logic as production handler).',
)]
final class Index extends Command
{
    public function __construct(
        private readonly IndexerConfigurationLoader $indexerConfigurationLoader,
        private readonly CrawlSiteRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = Command::SUCCESS;

        try {
            $config = $this->indexerConfigurationLoader->load("atooloTeaserCrawler");

            /** @var array<string, mixed> $data */
            $data = $config->data->get();

            /** @var array<array<string, mixed>> $sites */
            $sites = $data["sp_crawling_sites"] ?? [];

            if (empty($sites)) {
                $this->logger->warning('No crawler sites configured');
                return Command::SUCCESS;
            }

            $this->logger->info(sprintf('Starting crawler for %d sites', count($sites)));

            $failedSites = [];

            foreach ($sites as $site) {
                /** @var string $siteKey */
                $siteKey = $site['sp_id'] ?? null;

                if (empty($siteKey)) {
                    $this->logger->error('Invalid site config: missing "id" field');
                    $exitCode = Command::FAILURE;
                    continue;
                }

                try {
                    $this->runner->run($site);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'Crawling failed for "%s": %s',
                        $siteKey,
                        $e->getMessage()
                    ), [
                        'exception' => $e,
                        'site_id' => $siteKey,
                    ]);
                    $failedSites[] = $siteKey;
                    $exitCode = Command::FAILURE;
                    continue;
                }
            }

            if (!empty($failedSites)) {
                $this->logger->error(sprintf(
                    'Crawler failed for sites: %s',
                    implode(', ', $failedSites)
                ));
            } else {
                $this->logger->info('All sites crawled successfully');
            }
        } catch (\Throwable $e) {
            $this->logger->critical('Fatal error in crawler command', [
                'exception' => $e,
            ]);
            return Command::FAILURE;
        }

        return $exitCode;
    }
}
