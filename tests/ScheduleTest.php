<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Application\Schedule;
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
        $logger->expects($this->once())->method('info');

        $schedule = new Schedule(['0 * * * *', '30 8 * * 1-5'], $cache, $logger);
        $result = $schedule->getSchedule();

        $this->assertNotNull($result);
    }

    public function testGetScheduleLogsErrorOnInvalidCronExpression(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $schedule = new Schedule(['invalid-cron'], $cache, $logger);
        $result = $schedule->getSchedule();

        $this->assertNotNull($result); // still returns a schedule even on error
    }
}
