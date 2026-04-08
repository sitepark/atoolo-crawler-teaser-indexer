<?php

namespace Atoolo\Crawler\Application;

use Atoolo\Crawler\Application\StartCrawlerMessage;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        /** @var string[] $schedule*/
        private readonly array $schedule,
        private readonly IndexerConfigurationLoader $indexerConfigurationLoader,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        try {
            $config = $this->indexerConfigurationLoader->load("atooloTeaserCrawler");

            /** @var array<string, mixed> $params */
            $params = $config->data->get();

            /** @var array<string, mixed> $data */
            $data = $params["data"] ?? [];

            /** @var array<array<string, mixed>> $sites */
            $sites = $data["crawling_sites"] ?? [];

            if (empty($sites)) {
                $this->logger->warning('No crawler sites configured.');
                return $schedule;
            }

            $successCount = 0;
            foreach ($sites as $site) {
                foreach ($this->schedule as $scheduleTime) {
                    if ($this->isValidSite($site)) {
                        $schedule->add(
                            RecurringMessage::cron(
                                $scheduleTime,
                                new StartCrawlerMessage($site)
                            )
                        );
                        $successCount++;
                    }
                }
            }

            $this->logger->info(sprintf('Crawler scheduled for %d sites', $successCount));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Failed to load crawler schedule: %s', $e->getMessage()), [
                'exception' => $e,
            ]);
        }

        return $schedule;
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
