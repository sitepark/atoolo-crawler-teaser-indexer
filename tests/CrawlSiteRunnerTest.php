<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Application\CrawlSiteRunner;
use Atoolo\CrawlerIndexer\Config\PipelineConfigFactory;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipelineFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CrawlSiteRunnerTest extends TestCase
{
    private function makeRunner(
        CrawlerPipeline $manager,
        LoggerInterface $logger,
    ): CrawlSiteRunner {
        $pipelineFactory = $this->createMock(CrawlerPipelineFactory::class);
        $pipelineFactory->method('create')->willReturn($manager);

        return new CrawlSiteRunner(
            new PipelineConfigFactory($this->createStub(LoggerInterface::class)),
            $pipelineFactory,
            $logger,
        );
    }

    public function testRunWithValidSiteCallsStartCrawler(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->once())->method('startCrawler');

        $logger = $this->createStub(LoggerInterface::class);
        $this->makeRunner($manager, $logger)->run(['sp_id' => 'site-1']);
    }

    public function testRunWithMissingSiteIdThrowsInvalidArgumentException(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->never())->method('startCrawler');

        $logger = $this->createStub(LoggerInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->makeRunner($manager, $logger)->run([]);
    }

    public function testRunWithEmptySiteIdThrowsInvalidArgumentException(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $logger = $this->createStub(LoggerInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->makeRunner($manager, $logger)->run(['sp_id' => '']);
    }

    public function testRunLogsErrorAndRethrowsWhenCrawlerThrows(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->method('startCrawler')->willThrowException(new \RuntimeException('crawl error'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('crawl error');
        $this->makeRunner($manager, $logger)->run(['sp_id' => 'site-1']);
    }
}
