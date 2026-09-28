<?php

namespace Atoolo\CrawlerIndexer\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule(
    name: 'atoolo-crawler-teaser-indexer',
)]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        /** @var string[] $schedule */
        private readonly array $schedule,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * An invalid cron expression is not caught: it would otherwise be logged
     * once and the crawler would silently never run. Failing here surfaces
     * the misconfiguration as soon as the scheduler is started.
     *
     * @throws \InvalidArgumentException on an invalid cron expression
     */
    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache);

        foreach ($this->schedule as $scheduleTime) {
            try {
                $recurringMessage = RecurringMessage::cron(
                    $scheduleTime,
                    new StartPipelineMessage(),
                );
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException(sprintf('Invalid cron expression "%s" in atoolo.crawler.schedule: %s', $scheduleTime, $e->getMessage()), 0, $e);
            }

            $schedule->add($recurringMessage);
        }

        $this->logger->info(sprintf('Crawler scheduled with %d cron expression(s)', count($this->schedule)));

        return $schedule;
    }
}
