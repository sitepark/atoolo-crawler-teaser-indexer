<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Application\PipelineRunner;
use Atoolo\CrawlerIndexer\Application\SitesRunner;
use Atoolo\CrawlerIndexer\Config\PipelineConfigFactory;
use Atoolo\CrawlerIndexer\Exception\IndexingErrorsException;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipelineFactory;
use Atoolo\Resource\DataBag;
use Atoolo\Search\Dto\Indexer\IndexerConfiguration;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SitesRunnerTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private function makeLoader(array $data): IndexerConfigurationLoader
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn(new IndexerConfiguration(
            source: 'atooloTeaserCrawler',
            name: 'Crawler',
            data: new DataBag($data),
        ));

        return $loader;
    }

    /**
     * @param list<array<string, mixed>> $sites
     */
    private function makeSitesRunner(
        array $sites,
        CrawlerPipeline $manager,
        ?LoggerInterface $logger = null,
    ): SitesRunner {
        $pipelineFactory = $this->createMock(CrawlerPipelineFactory::class);
        $pipelineFactory->method('create')->willReturn($manager);

        $runner = new PipelineRunner(
            new PipelineConfigFactory($this->createStub(LoggerInterface::class)),
            $pipelineFactory,
            $this->createStub(LoggerInterface::class),
        );

        return new SitesRunner(
            $this->makeLoader(['sp_crawling_sites' => $sites]),
            $runner,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    public function testNoSitesLogsWarningAndIsSuccessful(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->never())->method('startCrawler');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $result = $this->makeSitesRunner([], $manager, $logger)->runAll();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->total);
    }

    public function testNoDataLogsWarningAndIsSuccessful(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $sitesRunner = new SitesRunner(
            $this->makeLoader([]),
            new PipelineRunner(
                new PipelineConfigFactory($this->createStub(LoggerInterface::class)),
                $this->createStub(CrawlerPipelineFactory::class),
                $this->createStub(LoggerInterface::class),
            ),
            $logger,
        );

        $this->assertTrue($sitesRunner->runAll()->isSuccessful());
    }

    public function testCrawlsAllValidSites(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->exactly(2))->method('startCrawler');

        $result = $this->makeSitesRunner(
            [['sp_id' => 'site-1'], ['sp_id' => 'site-2']],
            $manager,
        )->runAll();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->total);
        $this->assertSame([], $result->failedSites);
    }

    public function testSkipsSiteWithoutSpIdAndReportsItAsInvalid(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->once())->method('startCrawler');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Invalid site config: missing "sp_id" field');

        $result = $this->makeSitesRunner(
            [[], ['sp_id' => 'site-1']],
            $manager,
            $logger,
        )->runAll();

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(1, $result->invalidSites);
    }

    public function testContinuesWithNextSiteWhenOneSiteFails(): void
    {
        $calls = 0;
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->exactly(2))
            ->method('startCrawler')
            ->willReturnCallback(function () use (&$calls): void {
                ++$calls;
                if (1 === $calls) {
                    throw new \RuntimeException('site-1 exploded');
                }
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error');

        $result = $this->makeSitesRunner(
            [['sp_id' => 'site-1'], ['sp_id' => 'site-2']],
            $manager,
            $logger,
        )->runAll();

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(['site-1'], $result->failedSites);
    }

    /**
     * Indexer errors must reach the result - otherwise the command would exit
     * with success although documents were not indexed.
     */
    public function testSiteWithIndexingErrorsIsReportedAsFailed(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->method('startCrawler')
            ->willThrowException(new IndexingErrorsException(2, '[FINISHED] errors: 2'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->logicalOr(
                $this->stringContains('Crawling failed for "site-1": Indexing finished with 2 error(s)'),
                $this->stringContains('Crawler failed for sites: site-1'),
            ));

        $result = $this->makeSitesRunner([['sp_id' => 'site-1']], $manager, $logger)->runAll();

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(['site-1'], $result->failedSites);
    }

    public function testConfigLoadErrorPropagates(): void
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willThrowException(new \RuntimeException('config not found'));

        $sitesRunner = new SitesRunner(
            $loader,
            new PipelineRunner(
                new PipelineConfigFactory($this->createStub(LoggerInterface::class)),
                $this->createStub(CrawlerPipelineFactory::class),
                $this->createStub(LoggerInterface::class),
            ),
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('config not found');
        $sitesRunner->runAll();
    }
}
