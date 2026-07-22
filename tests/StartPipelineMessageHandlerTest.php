<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Application\PipelineRunner;
use Atoolo\CrawlerIndexer\Messenger\StartPipelineMessage;
use Atoolo\CrawlerIndexer\Messenger\StartPipelineMessageHandler;
use Atoolo\CrawlerIndexer\Config\PipelineConfigFactory;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipelineFactory;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\Resource\DataBag;
use Atoolo\Search\Dto\Indexer\IndexerConfiguration;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StartPipelineMessageHandlerTest extends TestCase
{
    private function makeConfig(array $sites): IndexerConfiguration
    {
        return new IndexerConfiguration(
            source: 'atooloTeaserCrawler',
            name: 'Crawler',
            data: new DataBag(['sp_crawling_sites' => $sites]),
        );
    }

    private function makeRunner(CrawlerPipeline $manager): PipelineRunner
    {
        $pipelineFactory = $this->createMock(CrawlerPipelineFactory::class);
        $pipelineFactory->method('create')->willReturn($manager);

        return new PipelineRunner(
            new PipelineConfigFactory($this->createStub(LoggerInterface::class)),
            $pipelineFactory,
            $this->createStub(LoggerInterface::class),
        );
    }

    public function testInvokeWithNoSitesLogsWarning(): void
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn($this->makeConfig([]));

        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->never())->method('startCrawler');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $handler = new StartPipelineMessageHandler($this->makeRunner($manager), $loader, $logger);
        $handler(new StartPipelineMessage());
    }

    public function testInvokeCallsRunnerForEachValidSite(): void
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

        $handler = new StartPipelineMessageHandler($this->makeRunner($manager), $loader, $logger);
        $handler(new StartPipelineMessage());
    }

    public function testInvokeSkipsInvalidSiteWithoutSpId(): void
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
        $logger->expects($this->once())->method('error');

        $handler = new StartPipelineMessageHandler($this->makeRunner($manager), $loader, $logger);
        $handler(new StartPipelineMessage());
    }
}
