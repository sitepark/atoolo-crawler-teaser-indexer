<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\CrawlerIndexer\Dto\ExtractedData;
use Atoolo\CrawlerIndexer\Exception\IndexingErrorsException;
use Atoolo\CrawlerIndexer\Exception\StepExecution;
use Atoolo\CrawlerIndexer\Pipeline\Indexer\Indexer;
use Atoolo\CrawlerIndexer\Pipeline\Parser\Parser;
use Atoolo\CrawlerIndexer\Pipeline\Processor\Processor;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLCollector;
use Atoolo\Search\Dto\Indexer\IndexerStatus;
use Atoolo\Search\Dto\Indexer\IndexerStatusState;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CrawlerPipelineTest extends TestCase
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

    /**
     * Wraps items in a generator, mirroring Parser::extractData()'s return
     * type (which cannot be produced by willReturn()).
     *
     * @param array<int, mixed> $items
     */
    private function toGenerator(array $items): \Generator
    {
        yield from $items;
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
            $indexer,
            $logger,
        );
    }

    /**
     * An Indexer mock that consumes the lazy chain like the real one does -
     * iterating is what makes the upstream steps run. The received documents
     * are written to $received.
     *
     * @param list<mixed>|null $received
     */
    private function consumingIndexer(?array &$received, int $errors = 0): Indexer
    {
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->willReturnCallback(function (iterable $documents) use (&$received, $errors): IndexerStatus {
                $received = [];
                foreach ($documents as $document) {
                    $received[] = $document;
                }

                return $this->makeIndexerStatus($errors);
            });

        return $indexer;
    }

    public function testFullCrawlerWorkflow(): void
    {
        $pages = [
            ['url' => $this->url1, 'html' => '<h1>Title 1</h1>'],
            ['url' => $this->url2, 'html' => '<h1>Title 2</h1>'],
        ];

        $parsed = [new ExtractedData($this->url1, 'Title 1'), new ExtractedData($this->url2, 'Title 2')];
        $processed = [new ExtractedData($this->url1, 'Title 1 Cleaned'), new ExtractedData($this->url2, 'Title 2 Cleaned')];

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturnCallback(fn(): \Generator => $this->toGenerator($parsed));

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturn($processed);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info');

        $this->makeManager(
            $this->stubUrlCollector([$pages]),
            $parser,
            $processor,
            $this->consumingIndexer($received),
            $logger,
        )->run($this->createConfig($this->createStub(LoggerInterface::class)));

        $this->assertSame($processed, $received);
    }

    /**
     * Every chunk streamed by URLCollector must be parsed and reach the
     * indexer, in order.
     */
    public function testChunksStreamedByUrlCollectorAreParsedAndMerged(): void
    {
        $chunk1 = [['url' => $this->url1, 'html' => '<h1>Title 1</h1>']];
        $chunk2 = [['url' => $this->url2, 'html' => '<h1>Title 2</h1>']];

        $doc1 = new ExtractedData($this->url1, 'Title 1');
        $doc2 = new ExtractedData($this->url2, 'Title 2');

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturnCallback(
            fn(array $pages): \Generator => $this->toGenerator($pages === $chunk1 ? [$doc1] : [$doc2]),
        );

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturnArgument(0);

        $this->makeManager(
            $this->stubUrlCollector([$chunk1, $chunk2]),
            $parser,
            $processor,
            $this->consumingIndexer($received),
            $this->createStub(LoggerInterface::class),
        )->run($this->createConfig($this->createStub(LoggerInterface::class)));

        $this->assertSame([$doc1, $doc2], $received);
    }

    /**
     * The chain is lazy: nothing is crawled or parsed until the indexer
     * iterates it, and the pipeline keeps no list of documents of its own.
     */
    public function testStepsOnlyRunWhileTheIndexerConsumes(): void
    {
        $events = [];

        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('collect')->willReturnCallback(
            function () use (&$events): \Generator {
                $events[] = 'collect page 1';
                yield [['url' => $this->url1, 'html' => '<h1>1</h1>']];
                $events[] = 'collect page 2';
                yield [['url' => $this->url2, 'html' => '<h1>2</h1>']];
            },
        );

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturnCallback(
            fn(array $pages): \Generator => $this->toGenerator([new ExtractedData($pages[0]['url'], 'T')]),
        );

        $indexer = $this->createMock(Indexer::class);
        $indexer->method('doIndex')->willReturnCallback(
            function (iterable $documents) use (&$events): IndexerStatus {
                $events[] = 'indexer starts';
                foreach ($documents as $document) {
                    $events[] = 'index ' . $document->getUrl();
                }

                return $this->makeIndexerStatus(0);
            },
        );

        $this->makeManager(
            $urlCollector,
            $parser,
            new Processor($this->createStub(LoggerInterface::class)),
            $indexer,
            $this->createStub(LoggerInterface::class),
        )->run($this->createConfig($this->createStub(LoggerInterface::class)));

        $this->assertSame([
            'indexer starts',
            'collect page 1',
            'index ' . $this->url1,
            'collect page 2',
            'index ' . $this->url2,
        ], $events);
    }

    public function testStopsWhenUrlCollectorYieldsNothing(): void
    {
        $this->makeManager(
            $this->stubUrlCollector([]),
            $this->createStub(Parser::class),
            new Processor($this->createStub(LoggerInterface::class)),
            $this->consumingIndexer($received),
            $this->createStub(LoggerInterface::class),
        )->run($this->createConfig($this->createStub(LoggerInterface::class)));

        $this->assertSame([], $received);
    }

    public function testUrlCollectorFailureIsReportedAsCollectorStepFailure(): void
    {
        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('collect')->willReturnCallback(
            static function (): \Generator {
                throw new \RuntimeException('Collector failed');

                yield [];
            },
        );

        $this->expectException(StepExecution::class);
        $this->expectExceptionMessage('Step [URLCollector] failed: Collector failed');

        $this->makeManager(
            $urlCollector,
            $this->createStub(Parser::class),
            new Processor($this->createStub(LoggerInterface::class)),
            $this->consumingIndexer($received),
            $this->createStub(LoggerInterface::class),
        )->run($this->createConfig($this->createStub(LoggerInterface::class)));
    }

    /**
     * A parser failure travels through the (downstream) processor guard while
     * the indexer consumes the chain - it must still be reported as a parser
     * failure, and logged only once.
     */
    public function testParserFailureKeepsItsStepNameThroughTheLazyChain(): void
    {
        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willThrowException(new \RuntimeException('broken HTML'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('[Parser] Error: broken HTML', $this->anything());

        $this->expectException(StepExecution::class);
        $this->expectExceptionMessage('Step [Parser] failed: broken HTML');

        $this->makeManager(
            $this->stubUrlCollector([[['url' => $this->url1, 'html' => '<h1>1</h1>']]]),
            $parser,
            new Processor($this->createStub(LoggerInterface::class)),
            $this->consumingIndexer($received),
            $logger,
        )->run($this->createConfig($this->createStub(LoggerInterface::class)));
    }

    public function testIndexerReturnsError(): void
    {
        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturnCallback(fn(): \Generator => $this->toGenerator([
            new ExtractedData($this->url1, 'Title'),
        ]));

        // Not logged here - SitesRunner logs the failure once, with the site id.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->expectException(IndexingErrorsException::class);
        $this->expectExceptionMessage('Indexing finished with 1 error(s)');

        $this->makeManager(
            $this->stubUrlCollector([[['url' => $this->url1, 'html' => '<h1>Title</h1>']]]),
            $parser,
            new Processor($this->createStub(LoggerInterface::class)),
            $this->consumingIndexer($received, 1),
            $logger,
        )->run($this->createConfig($this->createStub(LoggerInterface::class)));
    }

    /**
     * Uses the real Processor (a Generator) so its streaming path is
     * actually executed end to end.
     */
    public function testWorkflowWithRealProcessor(): void
    {
        $logger = $this->createStub(LoggerInterface::class);

        $chunk = [['url' => $this->url1, 'html' => '<h1>Title 1</h1>']];

        $parser = $this->createStub(Parser::class);
        $parser->method('extractData')->willReturnCallback(fn(): \Generator => $this->toGenerator([new ExtractedData($this->url1, '  <b>Title 1</b> ')]));

        $this->makeManager(
            $this->stubUrlCollector([$chunk]),
            $parser,
            new Processor($logger),
            $this->consumingIndexer($received),
            $logger,
        )->run($this->createConfig($logger));

        self::assertIsArray($received);
        $this->assertCount(1, $received);
        self::assertInstanceOf(ExtractedData::class, $received[0]);
        $this->assertSame('Title 1', $received[0]->getTitle());
    }
}
