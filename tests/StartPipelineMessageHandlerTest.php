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

/**
 * Only the wiring; the site loop itself is covered by SitesRunnerTest.
 */
final class StartPipelineMessageHandlerTest extends TestCase
{
    private function makeHandler(IndexerConfigurationLoader $loader, CrawlerPipeline $manager): StartPipelineMessageHandler
    {

        return new StartPipelineMessageHandler(new SitesRunner(
            $loader,
            new PipelineRunner(
                new PipelineConfigFactory($this->createStub(LoggerInterface::class)),
                $manager,
                $this->createStub(LoggerInterface::class),
            ),
            $this->createStub(LoggerInterface::class),
        ));
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
