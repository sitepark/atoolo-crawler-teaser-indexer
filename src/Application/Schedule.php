<?php

namespace Atoolo\Crawler\Application;

use Atoolo\Crawler\Application\StartCrawlerMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule(
    name: 'crawler:index',
)]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        /** @var string[] $schedule*/
        private readonly array $schedule,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache);

        try {
            $successCount = 0;
                foreach ($this->schedule as $scheduleTime) {
                        $schedule->add(
                            RecurringMessage::cron(
                                $scheduleTime,
                                new StartCrawlerMessage()
                            )
                        );
                        $successCount++;
                }

            $this->logger->info(sprintf('Crawler scheduled for %d sites', $successCount));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Failed to load crawler schedule: %s', $e->getMessage()), [
                'exception' => $e,
            ]);
        }

        return $schedule;
    }
}
