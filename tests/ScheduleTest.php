<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Messenger\Schedule;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class ScheduleTest extends TestCase
{
    public function testGetScheduleReturnsScheduleWithNoEntries(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $schedule = new Schedule([], $cache, $logger);
        $result = $schedule->getSchedule();

        $this->assertNotNull($result);
    }

    public function testGetScheduleAddsRecurringMessages(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Crawler scheduled with 2 cron expression(s)');

        $schedule = new Schedule(['0 * * * *', '30 8 * * 1-5'], $cache, $logger);
        $result = $schedule->getSchedule();

        $this->assertCount(2, $result->getRecurringMessages());
    }

    /**
     * An invalid expression must not be swallowed - otherwise the crawler
     * silently never runs.
     */
    public function testGetScheduleThrowsOnInvalidCronExpression(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $schedule = new Schedule(['invalid-cron'], $cache, $logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cron expression "invalid-cron" in atoolo.crawler.schedule');

        $schedule->getSchedule();
    }

    /**
     * A single bad entry fails the whole schedule, even after valid ones -
     * a partially registered schedule would hide the error just as well.
     */
    public function testGetScheduleThrowsWhenAnyExpressionIsInvalid(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $schedule = new Schedule(['0 * * * *', '61 * * * *'], $cache, $logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"61 * * * *"');

        $schedule->getSchedule();
    }
}
