<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Application\PipelineRunner;
use Atoolo\CrawlerIndexer\Application\SitesRunner;
use Atoolo\CrawlerIndexer\Config\PipelineConfigFactory;
use Atoolo\CrawlerIndexer\Messenger\StartPipelineMessage;
use Atoolo\CrawlerIndexer\Messenger\StartPipelineMessageHandler;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\Resource\DataBag;
use Atoolo\Search\Dto\Indexer\IndexerConfiguration;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Only the wiring; the site loop itself is covered by SitesRunnerTest.
 */
final class StartPipelineMessageHandlerTest extends TestCase
{
    private function makeHandler(
        IndexerConfigurationLoader $loader,
        CrawlerPipeline $manager,
        MockClock $clock = new MockClock(),
    ): StartPipelineMessageHandler {
        return new StartPipelineMessageHandler(
            new SitesRunner(
                $loader,
                new PipelineRunner(
                    new PipelineConfigFactory($this->createStub(LoggerInterface::class)),
                    $manager,
                    $this->createStub(LoggerInterface::class),
                ),
                $this->createStub(LoggerInterface::class),
            ),
            $this->createStub(LoggerInterface::class),
            $clock,
        );
    }

    private function oneSiteLoader(): IndexerConfigurationLoader
    {
        $loader = $this->createStub(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn(new IndexerConfiguration(
            source: 'atooloTeaserCrawler',
            name: 'Crawler',
            data: new DataBag(['sp_crawling_sites' => [['sp_id' => 'site-1']]]),
        ));

        return $loader;
    }

    /**
     * After three days down the schedule sends the three missed dates one
     * after the other. One crawl is enough.
     */
    public function testMissedRunsAfterADowntimeCrawlOnce(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->once())->method('run');

        $handler = $this->makeHandler($this->oneSiteLoader(), $manager, new MockClock('2026-11-14 03:00:00'));

        $handler(new StartPipelineMessage(new \DateTimeImmutable('2026-11-12 02:30:00')));
        $handler(new StartPipelineMessage(new \DateTimeImmutable('2026-11-13 02:30:00')));
        $handler(new StartPipelineMessage(new \DateTimeImmutable('2026-11-14 02:30:00')));
    }

    public function testEveryRegularRunCrawls(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->exactly(2))->method('run');

        $clock = new MockClock('2026-11-11 02:30:01');
        $handler = $this->makeHandler($this->oneSiteLoader(), $manager, $clock);

        $handler(new StartPipelineMessage(new \DateTimeImmutable('2026-11-11 02:30:00')));
        $clock->modify('2026-11-12 02:30:01');
        $handler(new StartPipelineMessage(new \DateTimeImmutable('2026-11-12 02:30:00')));
    }

    /**
     * A date that became due while the previous crawl was still running is
     * not covered by it - it was due after that crawl started.
     */
    public function testRunDueDuringALongCrawlCrawlsAgain(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->exactly(2))->method('run');

        $clock = new MockClock('2026-11-11 02:30:01');
        $handler = $this->makeHandler($this->oneSiteLoader(), $manager, $clock);

        $handler(new StartPipelineMessage(new \DateTimeImmutable('2026-11-11 02:30:00')));
        $clock->modify('2026-11-12 08:00:00');
        $handler(new StartPipelineMessage(new \DateTimeImmutable('2026-11-12 02:30:00')));
    }

    public function testMessageWithoutDueDateAlwaysCrawls(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->exactly(2))->method('run');

        $handler = $this->makeHandler($this->oneSiteLoader(), $manager);

        $handler(new StartPipelineMessage());
        $handler(new StartPipelineMessage());
    }

    public function testInvokeCrawlsConfiguredSitesAndSwallowsSiteFailures(): void
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn(new IndexerConfiguration(
            source: 'atooloTeaserCrawler',
            name: 'Crawler',
            data: new DataBag(['sp_crawling_sites' => [['sp_id' => 'site-1'], ['sp_id' => 'site-2']]]),
        ));

        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->exactly(2))
            ->method('run')
            ->willThrowException(new \RuntimeException('crawl failed'));

        // Must not throw: a failing site is not a failed message.
        ($this->makeHandler($loader, $manager))(new StartPipelineMessage());
    }

    public function testInvokeLetsConfigLoadErrorReachMessenger(): void
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willThrowException(new \RuntimeException('config not found'));

        $this->expectException(\RuntimeException::class);
        ($this->makeHandler($loader, $this->createStub(CrawlerPipeline::class)))(new StartPipelineMessage());
    }
}
