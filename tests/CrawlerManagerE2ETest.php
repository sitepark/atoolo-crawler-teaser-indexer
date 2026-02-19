<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Controller\CrawlerManager;
use Atoolo\Crawler\Domain\Crawler\Services\TeaserRelevanceEvaluatorInterface;
use Atoolo\Crawler\Domain\Crawler\Steps\URLCollector;
use Atoolo\Crawler\Domain\Crawler\Steps\Fetcher;
use Atoolo\Crawler\Domain\Crawler\Steps\Parser;
use Atoolo\Crawler\Domain\Crawler\Steps\Processor;
use Atoolo\Crawler\Domain\Crawler\Steps\Indexer;
use Atoolo\Search\Dto\Indexer\IndexerStatus;
use Atoolo\Search\Dto\Indexer\IndexerStatusState;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CrawlerManagerE2ETest extends TestCase
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
            ''
        );
    }

    /**
     * @param array<int|string> $overrides
     */
    private function createConfig(LoggerInterface $logger, array $overrides = []): CrawlerConfig
    {
        $ctx = new CrawlerConfigContext(array_merge([
            'atoolo.crawler.max_retry' => 1,
            'atoolo.crawler.delay_ms' => 0,
            'atoolo.crawler.retry_status_codes' => [408, 429, 500, 501, 502, 503, 504],
            'atoolo.crawler.concurrency_per_host' => 1,
            'atoolo.crawler.user_agent' => 'TestAgent/1.0',
            'atoolo.crawler.forced_article_urls' => [],
            'atoolo.crawler.content_scoring.active' => false,
            'atoolo.crawler.content_scoring.min_score' => 0,
            'atoolo.crawler.content_scoring.positive' => [],
            'atoolo.crawler.content_scoring.negative' => [],
        ], $overrides));

        $helper = new CrawlerConfigHelper($ctx, $logger);
        return new CrawlerConfig($helper);
    }

    public function testFullCrawlerWorkflow(): void
    {
        $urls = [$this->url1, $this->url2];
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

        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('findHrefUrlsByCssSelector')->willReturn($urls);

        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturn($pages);

        $parser = $this->createStub(Parser::class);
        $parser->method('extractTeasers')->willReturn($parsed);

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
                    $items
                );

                return true;
            }))
            ->willReturn($this->makeIndexerStatus(0));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('info');

        $config = $this->createConfig($logger);

        $manager = new CrawlerManager(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            $logger,
            $indexer
        );

        $manager->startCrawler();
    }

    public function testStopsWhenUrlCollectorReturnsEmpty(): void
    {
        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('findHrefUrlsByCssSelector')->willReturn([]);

        $fetcher = $this->createStub(Fetcher::class);
        $parser = $this->createStub(Parser::class);
        $processor = $this->createStub(Processor::class);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->equalTo([]))
            ->willReturn($this->makeIndexerStatus(0));

        $warnings = [];

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function ($message) use (&$warnings): void {
                $warnings[] = (string) $message;
            });

        $config = $this->createConfig($logger);

        $manager = new CrawlerManager($urlCollector, $fetcher, $parser, $processor, $config, $logger, $indexer);

        ob_start();
        try {
            $manager->startCrawler();
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue(
            array_reduce(
                $warnings,
                fn(bool $carry, string $m) => $carry || str_contains($m, '[URLCollector] Step returned no data.'),
                false
            ),
            'Expected warning "[URLCollector] Step returned no data." not found. Got: ' . implode(' | ', $warnings)
        );

        $this->assertStringNotContainsString('Title', $output);
        $this->assertStringNotContainsString('Cleaned', $output);
    }

    public function testLogsErrorWhenStepThrowsException(): void
    {
        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('findHrefUrlsByCssSelector')
            ->willThrowException(new \RuntimeException('Collector failed'));

        $fetcher = $this->createStub(Fetcher::class);
        $parser = $this->createStub(Parser::class);
        $processor = $this->createStub(Processor::class);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with($this->equalTo([]))
            ->willReturn($this->makeIndexerStatus(0));

        $errors = [];

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')
            ->willReturnCallback(function ($message) use (&$errors): void {
                $errors[] = (string) $message;
            });

        $config = $this->createConfig($logger);

        $manager = new CrawlerManager($urlCollector, $fetcher, $parser, $processor, $config, $logger, $indexer);

        ob_start();
        try {
            $manager->startCrawler();
        } finally {
            $output = (string) ob_get_clean();
        }

        $this->assertTrue(
            array_reduce(
                $errors,
                fn(bool $carry, string $m) => $carry || str_contains($m, '[URLCollector] Error: Collector failed'),
                false
            ),
            'Expected error "[URLCollector] Error: Collector failed" not found. Got: ' . implode(' | ', $errors)
        );

        $this->assertStringNotContainsString('Title', $output);
        $this->assertStringNotContainsString('Cleaned', $output);
    }

    public function testStopsWhenFetcherReturnsEmpty(): void
    {
        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('findHrefUrlsByCssSelector')->willReturn([$this->url1]);

        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturn([]);

        $warnings = [];

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')
            ->willReturnCallback(function ($message) use (&$warnings): void {
                $warnings[] = (string) $message;
            });

        $config = $this->createConfig($logger);

        $evaluator = $this->createStub(TeaserRelevanceEvaluatorInterface::class);
        $parser = new Parser($logger, $config, $evaluator);
        $processor = new Processor($logger);

        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())
            ->method('doIndex')
            ->with([])
            ->willReturn($this->makeIndexerStatus(0));

        $manager = new CrawlerManager(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            $logger,
            $indexer
        );

        $manager->startCrawler();

        $this->assertTrue(
            array_reduce(
                $warnings,
                fn(bool $carry, string $m) => $carry || str_contains($m, '[Fetcher] Step returned no data.'),
                false
            ),
            'Expected warning "[Fetcher] Step returned no data." not found. Got: ' . implode(' | ', $warnings)
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

        $urlCollector = $this->createStub(URLCollector::class);
        $urlCollector->method('findHrefUrlsByCssSelector')->willReturn([$this->url1]);

        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturn($pages);

        $parser = $this->createStub(Parser::class);
        $parser->method('extractTeasers')->willReturn($parsed);

        $processor = $this->createStub(Processor::class);
        $processor->method('sanitizeText')->willReturn($processed);

        $indexer = $this->createStub(Indexer::class);
        $indexer->method('doIndex')
            ->willReturn($this->makeIndexerStatus(1));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Status Errors [1]: Crawling Prozess Stops by Indexer.'));

        $config = $this->createConfig($logger);

        $manager = new CrawlerManager(
            $urlCollector,
            $fetcher,
            $parser,
            $processor,
            $config,
            $logger,
            $indexer
        );

        $manager->startCrawler();
    }
}
