<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Application\CrawlSiteRunner;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Controller\CrawlerManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CrawlSiteRunnerTest extends TestCase
{
    private function makeRunner(
        CrawlerManager $manager,
        LoggerInterface $logger,
    ): CrawlSiteRunner {
        return new CrawlSiteRunner(new CrawlerConfigContext([]), $manager, $logger);
    }

    public function testRunWithValidSiteCallsStartCrawler(): void
    {
        $manager = $this->createMock(CrawlerManager::class);
        $manager->expects($this->once())->method('startCrawler');

        $logger = $this->createStub(LoggerInterface::class);
        $this->makeRunner($manager, $logger)->run(['sp_id' => 'site-1']);
    }

    public function testRunWithMissingSiteIdThrowsInvalidArgumentException(): void
    {
        $manager = $this->createMock(CrawlerManager::class);
        $manager->expects($this->never())->method('startCrawler');

        $logger = $this->createStub(LoggerInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->makeRunner($manager, $logger)->run([]);
    }

    public function testRunWithEmptySiteIdThrowsInvalidArgumentException(): void
    {
        $manager = $this->createMock(CrawlerManager::class);
        $logger = $this->createStub(LoggerInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->makeRunner($manager, $logger)->run(['sp_id' => '']);
    }

    public function testRunResetsContextEvenWhenCrawlerThrows(): void
    {
        $manager = $this->createMock(CrawlerManager::class);
        $manager->method('startCrawler')->willThrowException(new \RuntimeException('crawl error'));

        $logger = $this->createStub(LoggerInterface::class);
        $ctx = new CrawlerConfigContext([]);
        $runner = new CrawlSiteRunner($ctx, $manager, $logger);

        try {
            $runner->run(['sp_id' => 'site-1', 'extra' => 'value']);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // After run() throws, the finally block must have called reset()
            $this->assertNull($ctx->get('sp_id'));
            $this->assertNull($ctx->get('extra'));
        }
    }

    public function testRunLogsErrorAndRethrowsWhenCrawlerThrows(): void
    {
        $manager = $this->createMock(CrawlerManager::class);
        $manager->method('startCrawler')->willThrowException(new \RuntimeException('crawl error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('crawl error');
        $this->makeRunner($manager, $logger)->run(['sp_id' => 'site-1']);
    }
}
