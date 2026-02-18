<?php

namespace Atoolo\Crawler\Application;

use Atoolo\Crawler\Application\StartCrawlerMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    /**
     * @param list<array{siteKey:string, schedule:string}> $sites
     */
    public function __construct(
        private readonly array $sites,
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        foreach ($this->sites as $site) {
            $schedule->add(
                RecurringMessage::cron(
                    $site["schedule"],
                    new StartCrawlerMessage($site["siteKey"])
                )
            );
        }

        return $schedule;
    }
}
