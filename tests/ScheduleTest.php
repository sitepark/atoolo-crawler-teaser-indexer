<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Messenger\Schedule;
use Atoolo\CrawlerIndexer\Messenger\StartPipelineMessage;
use Atoolo\Resource\DataBag;
use Atoolo\Resource\ResourceChannel;
use Atoolo\Resource\ResourceTenant;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Contracts\Cache\CacheInterface;

final class ScheduleTest extends TestCase
{
    public function testGetScheduleReturnsScheduleWithNoEntries(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $schedule = new Schedule([], $cache, $logger, $this->channel());
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

        $schedule = new Schedule(['0 * * * *', '30 8 * * 1-5'], $cache, $logger, $this->channel());
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

        $schedule = new Schedule(['invalid-cron'], $cache, $logger, $this->channel());

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

        $schedule = new Schedule(['0 * * * *', '61 * * * *'], $cache, $logger, $this->channel());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"61 * * * *"');

        $schedule->getSchedule();
    }

    /**
     * Each worker (Supervisor numprocs) builds its own schedule. The schedule
     * state is only saved after the message was handled, i.e. after the
     * crawl. Without the lock a second worker polling during the crawl would
     * start the same run again.
     */
    public function testOnlyOneWorkerTriggersTheCrawler(): void
    {
        $store = new InMemoryStore();
        $cache = new ArrayAdapter();
        $clock = new MockClock('2026-11-11 02:29:00');

        $workerA = $this->generator($store, $cache, $clock, 'live_index');
        $workerB = $this->generator($store, $cache, $clock, 'live_index');

        // first call initializes the schedule state
        iterator_to_array($workerA->getMessages());
        iterator_to_array($workerB->getMessages());

        $clock->modify('2026-11-11 02:30:01');

        // worker A takes the message and is still crawling (generator not resumed)
        $runningA = $workerA->getMessages();
        $this->assertInstanceOf(StartPipelineMessage::class, $runningA->current());

        // worker B polls in the meantime
        $this->assertCount(0, iterator_to_array($workerB->getMessages(), false));
    }

    /**
     * www and preview may run on the same host with the same lock store -
     * the lock of one channel must not stop the crawler of the other.
     */
    public function testChannelsDoNotBlockEachOther(): void
    {
        $store = new InMemoryStore();
        $clock = new MockClock('2026-11-11 02:29:00');

        $www = $this->generator($store, new ArrayAdapter(), $clock, 'www_index');
        $preview = $this->generator($store, new ArrayAdapter(), $clock, 'preview_index');

        iterator_to_array($www->getMessages());
        iterator_to_array($preview->getMessages());

        $clock->modify('2026-11-11 02:30:01');

        $this->assertCount(1, iterator_to_array($www->getMessages(), false));
        $this->assertCount(1, iterator_to_array($preview->getMessages(), false));
    }

    /**
     * A restarted worker continues from the stored state and sends every date
     * missed in between, each with its due date - the handler then crawls
     * only once (see StartPipelineMessageHandlerTest).
     */
    public function testRestartedWorkerSendsMissedDatesWithTheirDueDate(): void
    {
        $cache = new ArrayAdapter();
        $clock = new MockClock('2026-11-11 02:29:00');

        iterator_to_array($this->generator(new InMemoryStore(), $cache, $clock, 'live_index')->getMessages());

        // worker down for three days, then restarted
        $clock->modify('2026-11-14 03:00:00');
        $restarted = $this->generator(new InMemoryStore(), $cache, $clock, 'live_index');

        $dueDates = array_map(
            static function (object $message): string {
                self::assertInstanceOf(StartPipelineMessage::class, $message);

                return $message->scheduledAt?->format('Y-m-d H:i') ?? 'none';
            },
            iterator_to_array($restarted->getMessages(), false),
        );

        $this->assertSame(['2026-11-11 02:30', '2026-11-12 02:30', '2026-11-13 02:30', '2026-11-14 02:30'], $dueDates);
    }

    private function generator(
        InMemoryStore $store,
        CacheInterface $cache,
        MockClock $clock,
        string $searchIndex,
    ): MessageGenerator {
        $schedule = new Schedule(
            ['30 2 * * *'],
            $cache,
            new NullLogger(),
            $this->channel($searchIndex),
            new LockFactory($store),
        );

        return new MessageGenerator($schedule, 'atoolo-crawler-teaser-indexer', $clock);
    }

    private function channel(string $searchIndex = 'test'): ResourceChannel
    {
        return new ResourceChannel(
            '',
            '',
            '',
            '',
            false,
            '',
            'de_DE',
            '',
            '',
            '',
            $searchIndex,
            [],
            new DataBag([]),
            new ResourceTenant('', '', '', '', new DataBag([])),
        );
    }
}
