<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\CrawlerIndexer\Application\CrawlSiteRunner;
use Atoolo\CrawlerIndexer\Application\StartCrawlerMessage;
use Atoolo\CrawlerIndexer\Application\StartCrawlerMessageHandler;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigContext;
use Atoolo\CrawlerIndexer\Controller\CrawlerManager;
use Atoolo\Resource\DataBag;
use Atoolo\Search\Dto\Indexer\IndexerConfiguration;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StartCrawlerMessageHandlerTest extends TestCase
{
    private function makeConfig(array $sites): IndexerConfiguration
    {
        return new IndexerConfiguration(
            source: 'atooloTeaserCrawler',
            name: 'Crawler',
            data: new DataBag(['sp_crawling_sites' => $sites]),
        );
    }

    private function makeRunner(CrawlerManager $manager): CrawlSiteRunner
    {
        return new CrawlSiteRunner(
            new CrawlerConfigContext([]),
            $manager,
            $this->createStub(LoggerInterface::class),
        );
    }

    public function testInvokeWithNoSitesLogsWarning(): void
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn($this->makeConfig([]));

        $manager = $this->createMock(CrawlerManager::class);
        $manager->expects($this->never())->method('startCrawler');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $handler = new StartCrawlerMessageHandler($this->makeRunner($manager), $loader, $logger);
        $handler(new StartCrawlerMessage());
    }

    public function testInvokeCallsRunnerForEachValidSite(): void
    {
        $sites = [
            ['sp_id' => 'site-1'],
            ['sp_id' => 'site-2'],
        ];

        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn($this->makeConfig($sites));

        $manager = $this->createMock(CrawlerManager::class);
        $manager->expects($this->exactly(2))->method('startCrawler');

        $logger = $this->createStub(LoggerInterface::class);

        $handler = new StartCrawlerMessageHandler($this->makeRunner($manager), $loader, $logger);
        $handler(new StartCrawlerMessage());
    }

    public function testInvokeSkipsInvalidSiteWithoutSpId(): void
    {
        $sites = [
            [],                    // invalid: no sp_id
            ['sp_id' => 'site-1'], // valid
        ];

        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn($this->makeConfig($sites));

        $manager = $this->createMock(CrawlerManager::class);
        $manager->expects($this->once())->method('startCrawler');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new StartCrawlerMessageHandler($this->makeRunner($manager), $loader, $logger);
        $handler(new StartCrawlerMessage());
    }
}
