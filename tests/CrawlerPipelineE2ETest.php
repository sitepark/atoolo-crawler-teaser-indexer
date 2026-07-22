<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\CrawlerIndexer\Pipeline\ExecuteStep;
use Atoolo\CrawlerIndexer\Dto\ExtractedData;
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
     * @param array<int|string, mixed> $overrides
     */
    private function createConfig(LoggerInterface $logger, array $overrides = []): PipelineConfig
    {
        $ctx = array_merge([
            'sp_title_max_chars' => 140,
            'sp_introText_max_chars' => 280,
            'sp_content_scoring_active' => false,
            'sp_content_scoring_min_score' => 0,
            'sp_content_scoring_positive' => [],
            'sp_content_scoring_negative' => [],
        ], $overrides);

        return new PipelineConfig(new PipelineConfigHelper($ctx, $logger));
    }

    /**
     * Builds a URLCollector stub whose collect() yields the given chunks of
     * fetched HTML pages - mirroring the real streaming contract of
     * URLCollector::collect() (each page fetched exactly once, no return).
     *
     * @param list<array<int, array{url: string, html: string}>> $chunks
     */
    private function stubUrlCollector(array $chunks): URLCollector
    {
        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('collect')->willReturnCallback(
            static function () use ($chunks): \Generator {
                foreach ($chunks as $chunk) {
                    yield $chunk;
                }
            },
        );

        return $urlCollector;
    }

    private function makeManager(
        URLCollector $urlCollector,
        Parser $parser,
        Processor $processor,
        Indexer $indexer,
        LoggerInterface $logger,
    ): CrawlerPipeline {
        return new CrawlerPipeline(
            $urlCollector,
            $parser,
            $processor,
            new ExecuteStep($logger),
            $logger,
            $indexer,
        );
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

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturn($parsed);

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturn($processed);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->callback(function (array $items) use ($title1, $title2, $date1, $date2) {
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

        $this->makeManager(
            $this->stubUrlCollector([$pages]),
            $parser,
            $processor,
            $indexer,
            $logger,
        )->startCrawler();
    }

    /**
     * Every chunk streamed by URLCollector must be parsed and the resulting
     * documents merged before processing/indexing.
     */
    public function testChunksStreamedByUrlCollectorAreParsedAndMerged(): void
    {
        $chunk1 = [['url' => $this->url1, 'html' => '<h1>Title 1</h1>']];
        $chunk2 = [['url' => $this->url2, 'html' => '<h1>Title 2</h1>']];

        $doc1 = new ExtractedData($this->url1, 'Title 1');
        $doc2 = new ExtractedData($this->url2, 'Title 2');

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturnMap([
            [$chunk1, [$doc1]],
            [$chunk2, [$doc2]],
        ]);

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturnArgument(0);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->callback(function (array $items) use ($doc1, $doc2) {
                $this->assertSame([$doc1, $doc2], $items);

                return true;
            }))
            ->willReturn($this->makeIndexerStatus(0));

        $logger = $this->createStub(LoggerInterface::class);

        $this->makeManager(
            $this->stubUrlCollector([$chunk1, $chunk2]),
            $parser,
            $processor,
            $indexer,
            $logger,
        )->startCrawler();
    }

    public function testStopsWhenUrlCollectorYieldsNothing(): void
    {
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->equalTo([]))
            ->willReturn($this->makeIndexerStatus(0));

        $logger = $this->createStub(LoggerInterface::class);

        $this->makeManager(
            $this->stubUrlCollector([]),
            $this->createStub(Parser::class),
            $this->createStub(Processor::class),
            $indexer,
            $logger,
        )->startCrawler();
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

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->never())->method('doIndex');

        $logger = $this->createStub(LoggerInterface::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Collector failed');

        $this->makeManager(
            $urlCollector,
            $this->createStub(Parser::class),
            $this->createStub(Processor::class),
            $indexer,
            $logger,
        )->startCrawler();
    }

    public function testIndexerReturnsError(): void
    {
        $date = '2026-01-14';

        $pages = [
            ['url' => $this->url1, 'html' => '<h1>Title</h1><div class="smc-table-cell sidat">' . $date . '</div>'],
        ];

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturn([
            ['url' => $this->url1, 'title' => 'Title', 'date' => $date],
        ]);

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturn([
            ['url' => $this->url1, 'title' => 'Title Cleaned', 'date' => $date],
        ]);

        $indexer = $this->createStub(Indexer::class);
        $indexer->method('doIndex')->willReturn($this->makeIndexerStatus(1));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Status Errors [1]: Crawling Prozess Stops by Indexing.'));

        $this->makeManager(
            $this->stubUrlCollector([$pages]),
            $parser,
            $processor,
            $indexer,
            $logger,
        )->startCrawler();
    }

    /**
     * Uses the real Processor (a Generator) so its streaming path is
     * actually executed end to end.
     */
    public function testWorkflowWithRealProcessor(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $config = $this->createConfig($logger);

        $chunk = [['url' => $this->url1, 'html' => '<h1>Title 1</h1>']];

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturn([new ExtractedData($this->url1, 'Title 1')]);

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

        $this->makeManager(
            $this->stubUrlCollector([$chunk]),
            $parser,
            $processor,
            $indexer,
            $logger,
        )->startCrawler();
    }
}
