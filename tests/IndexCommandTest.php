<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Application\CrawlSiteRunner;
use Atoolo\CrawlerIndexer\Command\IndexCommand;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigContext;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\Resource\DataBag;
use Atoolo\Search\Dto\Indexer\IndexerConfiguration;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IndexCommandTest extends TestCase
{
    private function makeConfig(array $sites): IndexerConfiguration
    {
        return new IndexerConfiguration(
            source: 'atooloTeaserCrawler',
            name: 'Crawler',
            data: new DataBag(['sp_crawling_sites' => $sites]),
        );
    }

    private function makeRunner(CrawlerPipeline $manager): CrawlSiteRunner
    {
        return new CrawlSiteRunner(
            new CrawlerConfigContext([]),
            $manager,
            $this->createStub(LoggerInterface::class),
        );
    }

    private function runCommand(IndexCommand $command): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    public function testExecuteWithNoSitesReturnsSuccess(): void
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn($this->makeConfig([]));

        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->never())->method('startCrawler');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $command = new IndexCommand($loader, $this->makeRunner($manager), $logger);
        $tester = $this->runCommand($command);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testExecuteWithValidSitesCrawlsAllAndReturnsSuccess(): void
    {
        $sites = [
            ['sp_id' => 'site-1'],
            ['sp_id' => 'site-2'],
        ];

        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn($this->makeConfig($sites));

        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->exactly(2))->method('startCrawler');

        $logger = $this->createStub(LoggerInterface::class);

        $command = new IndexCommand($loader, $this->makeRunner($manager), $logger);
        $tester = $this->runCommand($command);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testExecuteWithSiteWithoutIdLogsErrorAndReturnsFailure(): void
    {
        $sites = [
            [],                    // invalid: no sp_id
            ['sp_id' => 'site-1'], // valid
        ];

        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn($this->makeConfig($sites));

        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->once())->method('startCrawler');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        $command = new IndexCommand($loader, $this->makeRunner($manager), $logger);
        $tester = $this->runCommand($command);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testExecuteWithSiteRunnerThrowingReturnsFailure(): void
    {
        $sites = [['sp_id' => 'site-1']];

        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn($this->makeConfig($sites));

        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->method('startCrawler')->willThrowException(new \RuntimeException('crawl failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        $command = new IndexCommand($loader, $this->makeRunner($manager), $logger);
        $tester = $this->runCommand($command);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testExecuteWithLoaderThrowingReturnsFatalFailure(): void
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willThrowException(new \RuntimeException('config not found'));

        $manager = $this->createMock(CrawlerPipeline::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('critical');

        $command = new IndexCommand($loader, $this->makeRunner($manager), $logger);
        $tester = $this->runCommand($command);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
