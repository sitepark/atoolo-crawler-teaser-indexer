<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\CrawlerConfig;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigContext;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigHelper;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\CrawlerIndexer\Pipeline\ExecuteStep;
use Atoolo\CrawlerIndexer\Dto\ExtractedData;
use Atoolo\CrawlerIndexer\Pipeline\Parser\RelevanceEvaluatorInterface;
use Atoolo\CrawlerIndexer\Pipeline\Fetcher\Fetcher;
use Atoolo\CrawlerIndexer\Pipeline\Indexer\Indexer;
use Atoolo\CrawlerIndexer\Pipeline\Parser\Parser;
use Atoolo\CrawlerIndexer\Pipeline\Processor\Processor;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLCollector;
use Atoolo\Search\Dto\Indexer\IndexerStatus;
use Atoolo\Search\Dto\Indexer\IndexerStatusState;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CrawlerPipelineE2ETest extends TestCase
{
    private string $url1 = 'https://example.com/page1';
    private string $url2 = 'https://example.com/page2';

    private function makeIndexerStatus(int $errors = 0): IndexerStatus
    {
        $now = new \DateTime();

        return new IndexerStatus(
            IndexerStatusState::FINISHED,
            $now,
            $now,
            0,
            0,
            0,
            $now,
            0,
            $errors,
            '',
        );
    }

    /**
     * @param array<int|string> $overrides
     */
    private function createConfig(LoggerInterface $logger, array $overrides = []): CrawlerConfig
    {
        $ctx = new CrawlerConfigContext(array_merge([
            'sp_max_retry' => 1,
            'sp_delay_ms' => 0,
            'sp_retry_status_codes' => [408, 429, 500, 501, 502, 503, 504],
            'sp_concurrency_per_host' => 1,
            'sp_user_agent' => 'TestAgent/1.0',
            'sp_forced_article_urls' => [],
            'sp_title_max_chars' => 140,
            'sp_introText_max_chars' => 280,
            'sp_content_scoring_active' => false,
            'sp_content_scoring_min_score' => 0,
            'sp_content_scoring_positive' => [],
            'sp_content_scoring_negative' => [],
        ], $overrides));

        $helper = new CrawlerConfigHelper($ctx, $logger);

        return new CrawlerConfig($helper);
    }

    /**
     * Builds a URLCollector stub whose collect() yields the given HTML
     * chunks (pages already fetched while following links) and, once
     * exhausted, returns the given boundary URLs - mirroring the real
     * generator contract of URLCollector::collect().
     *
     * @param list<array<int, array{url: string, html: string}>> $chunks
     * @param list<string>                                       $collectedUrls
     */
    private function stubUrlCollector(array $chunks, array $collectedUrls): URLCollector
    {
        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('collect')->willReturnCallback(
            static function () use ($chunks, $collectedUrls): \Generator {
                foreach ($chunks as $chunk) {
                    yield $chunk;
                }

                return $collectedUrls;
            },
        );

        return $urlCollector;
    }

    public function testFullCrawlerWorkflow(): void
    {
        $title1 = 'Title 1 Cleaned';
        $title2 = 'Title 2 Cleaned';
        $date1 = '2026-01-14';
        $date2 = '2026-01-15';

        $pages = [
            ['url' => $this->url1, 'html' => '<h1>Title 1</h1><div class="smc-table-cell sidat">' . $date1 . '</div>'],
            ['url' => $this->url2, 'html' => '<h1>Title 2</h1><div class="smc-table-cell sidat">' . $date2 . '</div>'],
        ];

        $parsed = [
            ['url' => $this->url1, 'title' => 'Title 1', 'date' => $date1],
            ['url' => $this->url2, 'title' => 'Title 2', 'date' => $date2],
        ];

        $processed = [
            ['url' => $this->url1, 'title' => $title1, 'date' => $date1],
            ['url' => $this->url2, 'title' => $title2, 'date' => $date2],
        ];

        $urlCollector = $this->stubUrlCollector([], [$this->url1, $this->url2]);

        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturn($pages);

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturn($parsed);

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturn($processed);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->callback(function (array $items) use ($title1, $title2, $date1, $date2) {
                $this->assertCount(2, $items);

                $this->assertSame(
                    [
                        ['url' => $this->url1, 'title' => $title1, 'date' => $date1],
                        ['url' => $this->url2, 'title' => $title2, 'date' => $date2],
                    ],
                    $items,
                );

                return true;
            }))
            ->willReturn($this->makeIndexerStatus(0));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info');

        $config = $this->createConfig($logger);

        $manager = new CrawlerPipeline(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            new ExecuteStep($logger),
            $logger,
            $indexer,
        );

        $manager->startCrawler();
    }

    /**
     * Pages that URLCollector already had to fetch while following links
     * (streamed as chunks from collect()) must be parsed by the manager
     * and merged with the Documents coming from the boundary-URL pipeline.
     */
    public function testChunksStreamedByUrlCollectorAreParsedAndMerged(): void
    {
        $inlineChunk = [
            ['url' => $this->url1, 'html' => '<h1>Title 1</h1>'],
        ];

        $urlCollector = $this->stubUrlCollector([$inlineChunk], [$this->url2]);

        $inlineDocument = new ExtractedData($this->url1, 'Title 1');
        $boundaryDocument = new ExtractedData($this->url2, 'Title 2');

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturnMap([
            [$inlineChunk, [$inlineDocument]],
            [[['url' => $this->url2, 'html' => '<h1>Title 2</h1>']], [$boundaryDocument]],
        ]);

        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturn([['url' => $this->url2, 'html' => '<h1>Title 2</h1>']]);

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturnArgument(0);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->callback(function (array $items) use ($inlineDocument, $boundaryDocument) {
                $this->assertSame([$inlineDocument, $boundaryDocument], $items);

                return true;
            }))
            ->willReturn($this->makeIndexerStatus(0));

        $logger = $this->createStub(LoggerInterface::class);
        $config = $this->createConfig($logger);

        $manager = new CrawlerPipeline(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            new ExecuteStep($logger),
            $logger,
            $indexer,
        );

        $manager->startCrawler();
    }

    public function testStopsWhenUrlCollectorReturnsEmpty(): void
    {
        $urlCollector = $this->stubUrlCollector([], []);

        $fetcher = $this->createStub(Fetcher::class);
        $parser = $this->createStub(Parser::class);
        $processor = $this->createStub(Processor::class);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->equalTo([]))
            ->willReturn($this->makeIndexerStatus(0));

        $logger = $this->createStub(LoggerInterface::class);
        $config = $this->createConfig($logger);

        $manager = new CrawlerPipeline(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            new ExecuteStep($logger),
            $logger,
            $indexer,
        );

        $manager->startCrawler();
    }

    public function testUrlCollectorFailurePropagatesDirectly(): void
    {
        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('collect')->willReturnCallback(
            static function (): \Generator {
                throw new \RuntimeException('Collector failed');

                yield [];
            },
        );

        $fetcher = $this->createStub(Fetcher::class);
        $parser = $this->createStub(Parser::class);
        $processor = $this->createStub(Processor::class);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->never())->method('doIndex');

        $logger = $this->createStub(LoggerInterface::class);
        $config = $this->createConfig($logger);

        $manager = new CrawlerPipeline(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            new ExecuteStep($logger),
            $logger,
            $indexer,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Collector failed');

        $manager->startCrawler();
    }

    public function testStopsWhenFetcherReturnsEmpty(): void
    {
        $urlCollector = $this->stubUrlCollector([], [$this->url1]);

        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturn([]);

        $warnings = [];

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function ($message) use (&$warnings): void {
                $warnings[] = (string) $message;
            });

        $config = $this->createConfig($logger);

        $evaluator = $this->createStub(RelevanceEvaluatorInterface::class);
        $parser = new Parser($logger, $config, $evaluator);
        $processor = new Processor($logger, $config);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with([])
            ->willReturn($this->makeIndexerStatus(0));

        $manager = new CrawlerPipeline(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            new ExecuteStep($logger),
            $logger,
            $indexer,
        );

        $manager->startCrawler();

        $this->assertTrue(
            array_reduce(
                $warnings,
                fn(bool $carry, string $m) => $carry || str_contains($m, '[Fetcher] Step returned no data.'),
                false,
            ),
            'Expected warning "[Fetcher] Step returned no data." not found. Got: ' . implode(' | ', $warnings),
        );
    }

    public function testIndexerReturnsError(): void
    {
        $date = '2026-01-14';

        $pages = [
            ['url' => $this->url1, 'html' => '<h1>Title</h1><div class="smc-table-cell sidat">' . $date . '</div>'],
        ];

        $parsed = [
            ['url' => $this->url1, 'title' => 'Title', 'date' => $date],
        ];

        $processed = [
            ['url' => $this->url1, 'title' => 'Title Cleaned', 'date' => $date],
        ];

        $urlCollector = $this->stubUrlCollector([], [$this->url1]);

        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturn($pages);

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturn($parsed);

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturn($processed);

        $indexer = $this->createStub(Indexer::class);
        $indexer->method('doIndex')
            ->willReturn($this->makeIndexerStatus(1));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Status Errors [1]: Crawling Prozess Stops by Indexing.'));

        $config = $this->createConfig($logger);

        $manager = new CrawlerPipeline(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            new ExecuteStep($logger),
            $logger,
            $indexer,
        );

        $manager->startCrawler();

        $this->addToAssertionCount(1);
    }

    /**
     * Uses real Processor (Generator) so that storageHandlingFetcherParser's
     * "yield $Document" line is actually executed.
     */
    public function testStorageHandlingYieldsDocumentsWithRealProcessor(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $config = $this->createConfig($logger);

        $pages = [
            ['url' => $this->url1, 'html' => '<h1>Title 1</h1>'],
        ];

        $urlCollector = $this->stubUrlCollector([], [$this->url1]);

        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturn($pages);

        $parsed = [new ExtractedData($this->url1, 'Title 1')];
        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturn($parsed);

        // Real Processor returns a Generator — this causes storageHandlingFetcherParser to yield
        $processor = new Processor($logger, $config);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->callback(function (array $items) {
                $this->assertCount(1, $items);
                $this->assertSame('Title 1', $items[0]->getTitle());

                return true;
            }))
            ->willReturn($this->makeIndexerStatus(0));

        $manager = new CrawlerPipeline(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            new ExecuteStep($logger),
            $logger,
            $indexer,
        );

        $manager->startCrawler();
    }
}
