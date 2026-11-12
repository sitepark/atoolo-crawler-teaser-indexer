<?php

namespace Atoolo\CrawlerIndexer\Messenger;

use Atoolo\Resource\ResourceChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CallbackMessageProvider;
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
        private readonly ResourceChannel $resourceChannel,
        private readonly LockFactory $lockFactory = new LockFactory(
            new SemaphoreStore(),
        ),
    ) {}

    /**
     * An invalid cron expression is not caught: it would otherwise be logged
     * once and the crawler would silently never run. Failing here surfaces
     * the misconfiguration as soon as the scheduler is started.
     *
     * Every worker consuming the scheduler transport builds its own schedule.
     * Without the lock each of them would trigger the crawler (numprocs=2 in
     * Supervisor = two crawls at the same time). The lock name contains the
     * search index, so channels sharing a host (e.g. www and preview) do not
     * block each other.
     *
     * Each message carries the date it was due at. The stateful schedule
     * replays every date missed during a downtime; the handler crawls once
     * and skips the dates that were due before that crawl started.
     *
     * @throws \InvalidArgumentException on an invalid cron expression
     */
    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache)
            ->lock($this->lockFactory->createLock(
                'atoolo-crawler-teaser-indexer-' . $this->resourceChannel->searchIndex,
            ));

        $message = new CallbackMessageProvider(
            static fn(MessageContext $context): array => [new StartPipelineMessage($context->triggeredAt)],
            'atoolo-crawler-teaser-indexer',
        );

        foreach ($this->schedule as $scheduleTime) {
            try {
                $recurringMessage = RecurringMessage::cron($scheduleTime, $message);
            } catch (\Throwable $e) {
                throw new \InvalidArgumentException(sprintf('Invalid cron expression "%s" in atoolo.crawler.schedule: %s', $scheduleTime, $e->getMessage()), 0, $e);
            }

            $schedule->add($recurringMessage);
        }

        $this->logger->info(sprintf('Crawler scheduled with %d cron expression(s)', count($this->schedule)));

        return $schedule;
    }
}
