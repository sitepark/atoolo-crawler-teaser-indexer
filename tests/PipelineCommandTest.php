<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Application\PipelineRunner;
use Atoolo\CrawlerIndexer\Application\SitesRunner;
use Atoolo\CrawlerIndexer\Command\PipelineCommand;
use Atoolo\CrawlerIndexer\Config\PipelineConfigFactory;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipelineFactory;
use Atoolo\Resource\DataBag;
use Atoolo\Search\Dto\Indexer\IndexerConfiguration;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Only the exit-code mapping; the site loop itself is covered by SitesRunnerTest.
 */
final class PipelineCommandTest extends TestCase
{
    private function makeSitesRunner(IndexerConfigurationLoader $loader, CrawlerPipeline $manager): SitesRunner
    {
        $pipelineFactory = $this->createMock(CrawlerPipelineFactory::class);
        $pipelineFactory->method('create')->willReturn($manager);

        return new SitesRunner(
            $loader,
            new PipelineRunner(
                new PipelineConfigFactory($this->createStub(LoggerInterface::class)),
                $pipelineFactory,
                $this->createStub(LoggerInterface::class),
            ),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function makeLoader(array $sites): IndexerConfigurationLoader
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willReturn(new IndexerConfiguration(
            source: 'atooloTeaserCrawler',
            name: 'Crawler',
            data: new DataBag(['sp_crawling_sites' => $sites]),
        ));

        return $loader;
    }

    private function execute(PipelineCommand $command): int
    {
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester->getStatusCode();
    }

    public function testSuccessfulRunReturnsSuccess(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->expects($this->once())->method('startCrawler');

        $command = new PipelineCommand(
            $this->makeSitesRunner($this->makeLoader([['sp_id' => 'site-1']]), $manager),
            $this->createStub(LoggerInterface::class),
        );

        $this->assertSame(Command::SUCCESS, $this->execute($command));
    }

    public function testFailedSiteReturnsFailure(): void
    {
        $manager = $this->createMock(CrawlerPipeline::class);
        $manager->method('startCrawler')->willThrowException(new \RuntimeException('crawl failed'));

        $command = new PipelineCommand(
            $this->makeSitesRunner($this->makeLoader([['sp_id' => 'site-1']]), $manager),
            $this->createStub(LoggerInterface::class),
        );

        $this->assertSame(Command::FAILURE, $this->execute($command));
    }

    public function testInvalidSiteReturnsFailure(): void
    {
        $command = new PipelineCommand(
            $this->makeSitesRunner($this->makeLoader([[]]), $this->createStub(CrawlerPipeline::class)),
            $this->createStub(LoggerInterface::class),
        );

        $this->assertSame(Command::FAILURE, $this->execute($command));
    }

    public function testLoaderThrowingLogsCriticalAndReturnsFailure(): void
    {
        $loader = $this->createMock(IndexerConfigurationLoader::class);
        $loader->method('load')->willThrowException(new \RuntimeException('config not found'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('critical');

        $command = new PipelineCommand(
            $this->makeSitesRunner($loader, $this->createStub(CrawlerPipeline::class)),
            $logger,
        );

        $this->assertSame(Command::FAILURE, $this->execute($command));
    }
}
