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
            /** @var array<string, mixed> $params */
            $params = $config->data->get();

            /** @var array<string, mixed> $data */
            $data = $params["data"] ?? [];

            /** @var array<array<string, mixed>> $sites */
            $sites = $data["crawling_sites"] ?? [];

            if (empty($sites)) {
                $output->writeln('<comment>No sites configured.</comment>');
                $this->logger->warning('No crawler sites configured');
                return Command::SUCCESS;
            }

            $this->logger->info(sprintf('Starting crawler for %d sites', count($sites)));
            $output->writeln(sprintf('<info>Starting crawler for %d sites</info>', count($sites)));

            $failedSites = [];

            foreach ($sites as $site) {
                /** @var string $siteKey */
                $siteKey = $site['id'] ?? null;

                if (empty($siteKey)) {
                    $output->writeln('<error>Invalid site config: missing "id" field.</error>');
                    $this->logger->error('Invalid site config: missing "id" field');
                    $exitCode = Command::FAILURE;
                    continue;
                }

                try {
                    $output->writeln(sprintf('<info>Processing site: %s</info>', $siteKey));
                    $this->logger->info(sprintf('Processing site: %s', $siteKey));

                    $this->runner->run($site);

                    $output->writeln(sprintf('<fg=green>✓ Successfully crawled: %s</>', $siteKey));
                    $this->logger->info(sprintf('Successfully crawled: %s', $siteKey));
                } catch (\Throwable $e) {
                    $output->writeln(sprintf(
                        '<error>✗ Crawling failed for "%s": %s</error>',
                        $siteKey,
                        $e->getMessage()
                    ));
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
                $output->writeln(sprintf(
                    '<error>Failed sites: %s</error>',
                    implode(', ', $failedSites)
                ));
                $this->logger->error(sprintf(
                    'Crawler failed for sites: %s',
                    implode(', ', $failedSites)
                ));
            } else {
                $output->writeln('<fg=green>All sites crawled successfully!</>');
                $this->logger->info('All sites crawled successfully');
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Fatal error: %s</error>', $e->getMessage()));
            $this->logger->critical('Fatal error in crawler command', [
                'exception' => $e,
            ]);
            return Command::FAILURE;
        }

        return $exitCode;
    }
}
