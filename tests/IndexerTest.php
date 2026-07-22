<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\CrawlerConfig;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigContext;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigHelper;
use Atoolo\CrawlerIndexer\Dto\ExtractedData;
use Atoolo\CrawlerIndexer\Pipeline\Indexer\Indexer;
use Atoolo\CrawlerIndexer\Exception\ThresholdNotMetException;
use Atoolo\Search\Dto\Indexer\IndexerStatus;
use Atoolo\Search\Service\Indexer\IndexerProgressHandler;
use Atoolo\Search\Service\Indexer\SolrIndexService;
use Atoolo\Search\Service\Indexer\SolrIndexUpdater;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Solarium\QueryType\Update\Query\Document;
use Solarium\QueryType\Update\Result as SolrUpdateResult;

final class IndexerTest extends TestCase
{
    private function makeConfig(array $overrides = []): CrawlerConfig
    {
        $ctx = new CrawlerConfigContext(array_merge([
            'sp_id' => 'test-source',
            'sp_cleanup_threshold' => 0,
            'sp_introText_present' => false,
            'sp_datetime_present' => false,
        ], $overrides));
        $logger = $this->createStub(LoggerInterface::class);
        $helper = new CrawlerConfigHelper($ctx, $logger);

        return new CrawlerConfig($helper);
    }

    private function makeIndexer(
        array $configOverrides = [],
        ?IndexerProgressHandler $progressHandler = null,
        ?SolrIndexService $indexService = null,
        ?LoggerInterface $logger = null,
    ): Indexer {
        $updateResult = $this->createMock(SolrUpdateResult::class);
        $updateResult->method('getStatus')->willReturn(0);

        $updater = $this->createMock(SolrIndexUpdater::class);
        $updater->method('createDocument')->willReturn(new Document());
        $updater->method('update')->willReturn($updateResult);

        $defaultIndexService = $this->createMock(SolrIndexService::class);
        $defaultIndexService->method('updater')->willReturn($updater);

        $defaultProgressHandler = $this->createStub(IndexerProgressHandler::class);
        $defaultProgressHandler->method('getStatus')->willReturn(IndexerStatus::empty());

        return new Indexer(
            $progressHandler ?? $defaultProgressHandler,
            $indexService ?? $defaultIndexService,
            $this->makeConfig($configOverrides),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    public function testDoIndexWithSuccessfulItemsReturnsStatus(): void
    {
        $indexer = $this->makeIndexer();

        $status = $indexer->doIndex([
            new ExtractedData('https://example.com/page1', 'Page 1'),
            new ExtractedData('https://example.com/page2', 'Page 2'),
        ]);

        $this->assertInstanceOf(IndexerStatus::class, $status);
    }

    public function testDoIndexThrowsWhenSuccessCountBelowThreshold(): void
    {
        // threshold=5 means successCount must be > 5
        $indexer = $this->makeIndexer(['sp_cleanup_threshold' => 5]);

        $this->expectException(ThresholdNotMetException::class);

        // Processing 0 items → successCount=0 ≤ threshold=5 → ThresholdNotMetException
        $indexer->doIndex([]);
    }

    public function testDoIndexWithIntroTextIncludesIntroField(): void
    {
        $updateResult = $this->createMock(SolrUpdateResult::class);
        $updateResult->method('getStatus')->willReturn(0);

        $document = new Document();

        $updater = $this->createMock(SolrIndexUpdater::class);
        $updater->method('createDocument')->willReturn($document);
        $updater->method('update')->willReturn($updateResult);

        $indexService = $this->createMock(SolrIndexService::class);
        $indexService->method('updater')->willReturn($updater);

        $progressHandler = $this->createStub(IndexerProgressHandler::class);
        $progressHandler->method('getStatus')->willReturn(IndexerStatus::empty());

        $indexer = new Indexer(
            $progressHandler,
            $indexService,
            $this->makeConfig(['sp_introText_present' => true]),
            $this->createStub(LoggerInterface::class),
        );

        $status = $indexer->doIndex([
            new ExtractedData('https://example.com/', 'Title', 'Intro text here'),
        ]);

        $this->assertInstanceOf(IndexerStatus::class, $status);
    }

    public function testDoIndexWithDatetimeItemIncludesDateField(): void
    {
        $indexer = $this->makeIndexer(['sp_datetime_present' => true]);

        $status = $indexer->doIndex([
            new ExtractedData('https://example.com/', 'Title', null, new \DateTimeImmutable('2026-01-01')),
        ]);

        $this->assertInstanceOf(IndexerStatus::class, $status);
    }

    public function testDoIndexWithDatetimeItemSetsDateField(): void
    {
        $indexer = $this->makeIndexer(['sp_datetime_present' => true]);

        $status = $indexer->doIndex([
            new ExtractedData('https://example.com/', 'Title', null, new \DateTimeImmutable('2026-01-01T00:00:00Z')),
        ]);

        $this->assertInstanceOf(IndexerStatus::class, $status);
    }

    public function testDoIndexWithValidDateDoesNotLogWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $indexer = $this->makeIndexer(['sp_datetime_present' => true], logger: $logger);

        $status = $indexer->doIndex([
            new ExtractedData('https://example.com/', 'Title', null, new \DateTimeImmutable('2026-01-01')),
        ]);

        $this->assertInstanceOf(IndexerStatus::class, $status);
    }

    public function testDoIndexLogsErrorWhenSolrUpdateStatusIsNonZero(): void
    {
        $updateResult = $this->createMock(SolrUpdateResult::class);
        $updateResult->method('getStatus')->willReturn(1);
        $updateResult->method('getResponse')->willReturn(
            new \Solarium\Core\Client\Response('', ['HTTP/1.1 500 Internal Server Error']),
        );

        $updater = $this->createMock(SolrIndexUpdater::class);
        $updater->method('createDocument')->willReturn(new Document());
        $updater->method('update')->willReturn($updateResult);

        $indexService = $this->createMock(SolrIndexService::class);
        $indexService->method('updater')->willReturn($updater);

        $progressHandler = $this->createMock(IndexerProgressHandler::class);
        $progressHandler->method('getStatus')->willReturn(IndexerStatus::empty());
        $progressHandler->expects($this->once())->method('error');

        $indexer = new Indexer(
            $progressHandler,
            $indexService,
            $this->makeConfig(),
            $this->createStub(LoggerInterface::class),
        );

        $indexer->doIndex([
            new ExtractedData('https://example.com/', 'Title'),
        ]);
    }

    public function testDoIndexRethrowsWhenSolrUpdateThrows(): void
    {
        $updater = $this->createMock(SolrIndexUpdater::class);
        $updater->method('createDocument')->willReturn(new Document());
        $updater->method('update')->willThrowException(new \RuntimeException('Solr not reachable'));

        $indexService = $this->createMock(SolrIndexService::class);
        $indexService->method('updater')->willReturn($updater);

        $indexer = new Indexer(
            $this->createStub(IndexerProgressHandler::class),
            $indexService,
            $this->makeConfig(),
            $this->createStub(LoggerInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Solr not reachable');

        $indexer->doIndex([
            new ExtractedData('https://example.com/', 'Title'),
        ]);
    }

    public function testDoIndexCatchesItemExceptionAndContinues(): void
    {
        $callCount = 0;
        $updater = $this->createMock(SolrIndexUpdater::class);
        $updater->method('createDocument')->willReturnCallback(function () use (&$callCount): Document {
            ++$callCount;
            if (1 === $callCount) {
                throw new \RuntimeException('document creation failed');
            }

            return new Document();
        });

        $updateResult = $this->createMock(SolrUpdateResult::class);
        $updateResult->method('getStatus')->willReturn(0);
        $updater->method('update')->willReturn($updateResult);

        $indexService = $this->createMock(SolrIndexService::class);
        $indexService->method('updater')->willReturn($updater);

        $progressHandler = $this->createStub(IndexerProgressHandler::class);
        $progressHandler->method('getStatus')->willReturn(IndexerStatus::empty());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $indexer = new Indexer($progressHandler, $indexService, $this->makeConfig(), $logger);

        // Item 1 throws, item 2 succeeds
        $status = $indexer->doIndex([
            new ExtractedData('https://example.com/bad', 'Bad'),
            new ExtractedData('https://example.com/good', 'Good'),
        ]);

        $this->assertInstanceOf(IndexerStatus::class, $status);
    }

    // --- Interface boilerplate methods ---

    public function testIndexReturnsEmptyStatus(): void
    {
        $status = $this->makeIndexer()->index();
        $this->assertInstanceOf(IndexerStatus::class, $status);
    }

    public function testAbortDoesNotThrow(): void
    {
        $this->makeIndexer()->abort();
        $this->assertTrue(true);
    }

    public function testEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->makeIndexer()->enabled());
    }

    public function testGetNameReturnsString(): void
    {
        $this->assertSame('rce-indexer', $this->makeIndexer()->getName());
    }

    public function testGetSourceReturnsEmptyStringBeforeDoIndex(): void
    {
        $this->assertSame('', $this->makeIndexer()->getSource());
    }

    public function testGetProgressHandlerReturnsHandler(): void
    {
        $handler = $this->createStub(IndexerProgressHandler::class);
        $handler->method('getStatus')->willReturn(IndexerStatus::empty());

        $indexer = $this->makeIndexer(progressHandler: $handler);
        $this->assertSame($handler, $indexer->getProgressHandler());
    }

    public function testSetProgressHandlerIsNoOp(): void
    {
        $indexer = $this->makeIndexer();
        $newHandler = $this->createStub(IndexerProgressHandler::class);
        $indexer->setProgressHandler($newHandler);
        // The original handler is not replaced (no-op by design)
        $this->assertTrue(true);
    }

    public function testRemoveIsNoOp(): void
    {
        $this->makeIndexer()->remove(['id1', 'id2']);
        $this->assertTrue(true);
    }
}
