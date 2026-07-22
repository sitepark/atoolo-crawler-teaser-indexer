<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigHelper;
use Atoolo\CrawlerIndexer\Pipeline\Collector\RobotsTxtCheckerInterface;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLNormalizer;
use Atoolo\CrawlerIndexer\Pipeline\Fetcher\Fetcher;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the URLCollector class.
 *
 * URLCollector::collect() is a generator: it yields chunks of fetched
 * HTML pages (the ones it had to fetch anyway to follow links up to a
 * start URL's extraction_depth) and, once exhausted, returns the list of
 * URLs discovered beyond that depth via getReturn(). Parsing those pages
 * into documents is the caller's (CrawlerPipeline) responsibility.
 *
 * This test suite ensures that:
 * - Links are extracted, filtered and returned as the generator's return value.
 * - Relative URLs are resolved against the base URL.
 * - Fetched pages are streamed out as chunks while crawling.
 * - Broken or invalid links are logged and skipped.
 * - Edge cases such as duplicate links, empty content and depth/limit boundaries are handled correctly.
 */
final class URLCollectorTest extends TestCase
{
    private string $url1 = 'https://example.com/page1';
    private string $urlPrefix = 'https://example.com';

    /**
     * @param array<string, string> $htmlByUrl
     */
    private function stubFetcher(array $htmlByUrl): Fetcher
    {
        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willReturnCallback(
            function (array $urls) use ($htmlByUrl): array {
                $result = [];
                foreach ($urls as $url) {
                    if (isset($htmlByUrl[$url])) {
                        $result[] = ['url' => $url, 'html' => $htmlByUrl[$url]];
                    }
                }

                return $result;
            },
        );

        return $fetcher;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createCollector(
        Fetcher $fetcher,
        LoggerInterface $logger,
        RobotsTxtCheckerInterface $robotsTxtChecker,
        array $overrides = [],
    ): URLCollector {
        $ctx = array_merge([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 0]],
            'sp_link_selector' => '#content a[href]',
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [$this->urlPrefix],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ], $overrides);

        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new PipelineConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, []);

        return new URLCollector($crawlerConfig, $urlNormalizer, $logger, $robotsTxtChecker, $fetcher);
    }

    /**
     * @return list<array<int, array{url: string, html: string}>>
     */
    private function collectChunks(URLCollector $collector, \Generator $generator): array
    {
        return iterator_to_array($generator);
    }

    public function testCollectYieldsFetchedPageAndReturnsDiscoveredLinks(): void
    {
        $html = <<<HTML
<!doctype html>
<html><body id="content">
<a href="$this->url1">Page 1</a>
<a href="https://example.com/page2">Page 2</a>
<a href="/relative">Relative link</a>
<a href="$this->url1">Page 1 again</a>
</body></html>
HTML;

        $fetcher = $this->stubFetcher([$this->urlPrefix => $html]);
        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker);
        $generator = $collector->collect();

        $chunks = $this->collectChunks($collector, $generator);
        $this->assertSame(
            [[['url' => $this->urlPrefix, 'html' => $html]]],
            $chunks,
        );

        $this->assertSame(
            [$this->url1, 'https://example.com/page2', 'https://example.com/relative'],
            $generator->getReturn(),
        );
    }

    public function testBrokenLinkIsIgnored(): void
    {
        $html = '<div id="content">'
            . '<a href="javascript:void(0)">Broken</a>'
            . '</div>';

        $fetcher = $this->stubFetcher([$this->urlPrefix => $html]);
        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker);
        $generator = $collector->collect();
        iterator_to_array($generator);

        $this->assertSame([], $generator->getReturn());
    }

    /**
     * Using a selector that matches non-<a> elements causes Symfony's Link class
     * to throw a LogicException ("Unable to navigate from a div tag."),
     * which is caught and logged as debug.
     */
    public function testNonAnchorElementIsCaughtAndIgnored(): void
    {
        $html = '<div id="content"><div href="https://example.com/page">link</div></div>';

        $fetcher = $this->stubFetcher([$this->urlPrefix => $html]);
        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker, [
            'sp_link_selector' => '#content div[href]',
        ]);
        $generator = $collector->collect();
        iterator_to_array($generator);

        $this->assertSame([], $generator->getReturn());
    }

    public function testEmptyHtmlContentReturnsNoLinks(): void
    {
        $fetcher = $this->stubFetcher([$this->urlPrefix => '']);
        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker);
        $generator = $collector->collect();
        iterator_to_array($generator);

        $this->assertSame([], $generator->getReturn());
    }

    public function testRespectRobotsTxtFiltersThroughChecker(): void
    {
        $html = <<<HTML
<!doctype html>
<html><body id="content">
<a href="https://example.com/page1">Page 1</a>
<a href="https://example.com/page2">Page 2</a>
</body></html>
HTML;

        $fetcher = $this->stubFetcher([$this->urlPrefix => $html]);
        $logger = $this->createStub(LoggerInterface::class);

        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->expects($this->once())
            ->method('filterAllowed')
            ->willReturn(['https://example.com/page1']);

        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker, [
            'sp_respect_robots_txt' => true,
        ]);
        $generator = $collector->collect();
        iterator_to_array($generator);

        $this->assertSame(['https://example.com/page1'], $generator->getReturn());
    }

    public function testCollectByDepthFollowsLinksAndYieldsEachFetchedPage(): void
    {
        $indexHtml = <<<HTML
<!doctype html>
<html><body id="content">
<a href="https://example.com/section">Section</a>
</body></html>
HTML;

        $sectionHtml = <<<HTML
<!doctype html>
<html><body id="content">
<a href="https://example.com/article">Article</a>
</body></html>
HTML;

        $fetcher = $this->stubFetcher([
            $this->urlPrefix => $indexHtml,
            'https://example.com/section' => $sectionHtml,
        ]);
        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker, [
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 1]],
        ]);
        $generator = $collector->collect();

        $chunks = $this->collectChunks($collector, $generator);

        // Both the start page and the discovered section page had to be
        // fetched to follow links, so both are streamed out as chunks.
        $this->assertSame(
            [
                [['url' => $this->urlPrefix, 'html' => $indexHtml]],
                [['url' => 'https://example.com/section', 'html' => $sectionHtml]],
            ],
            $chunks,
        );

        $this->assertSame(
            ['https://example.com/section', 'https://example.com/article'],
            $generator->getReturn(),
        );
    }

    public function testAlreadyVisitedUrlInQueueIsSkipped(): void
    {
        $startHtml = <<<HTML
<html><body id="content">
<a href="https://example.com/page-a">Page A</a>
<a href="https://example.com/page-b">Page B</a>
</body></html>
HTML;

        $pageAHtml = <<<HTML
<html><body id="content">
<a href="https://example.com/page-b">Page B again</a>
</body></html>
HTML;

        $fetcher = $this->stubFetcher([
            $this->urlPrefix => $startHtml,
            'https://example.com/page-a' => $pageAHtml,
            'https://example.com/page-b' => '<html><body id="content"></body></html>',
        ]);
        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker, [
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 2]],
            'sp_allow_prefixes' => [],
        ]);
        $generator = $collector->collect();
        $chunks = $this->collectChunks($collector, $generator);

        // page-b is discovered from two pages but fetched only once.
        $this->assertCount(3, $chunks);
        $this->assertSame(
            ['https://example.com/page-a', 'https://example.com/page-b', 'https://example.com/page-b'],
            $generator->getReturn(),
        );
    }

    public function testMaxDStopsCollectionGlobally(): void
    {
        $html = <<<HTML
<!doctype html>
<html><body id="content">
<a href="https://example.com/page1">Page 1</a>
<a href="https://example.com/page2">Page 2</a>
<a href="https://example.com/page3">Page 3</a>
</body></html>
HTML;

        $fetcher = $this->stubFetcher([$this->urlPrefix => $html]);
        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker, [
            'sp_max_teaser' => 2,
        ]);
        $generator = $collector->collect();
        iterator_to_array($generator);

        $this->assertSame(
            ['https://example.com/page1', 'https://example.com/page2'],
            $generator->getReturn(),
        );
    }

    public function testFetcherFailurePropagatesWhileIterating(): void
    {
        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willThrowException(new \RuntimeException('Connection failed'));

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);
        $collector = $this->createCollector($fetcher, $logger, $robotsTxtChecker);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection failed');

        iterator_to_array($collector->collect());
    }
}
