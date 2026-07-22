<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Pipeline\Collector\RobotsTxtCheckerInterface;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLNormalizer;
use Atoolo\CrawlerIndexer\Pipeline\Fetcher\Fetcher;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the URLCollector.
 *
 * URLCollector::collect() is a generator that performs a breadth-first
 * crawl and yields chunks of fetched HTML pages. Every URL is fetched
 * exactly once (a whole BFS level is fetched in chunks of
 * `sp_parallel_requests`), and links are discovered from the fetched HTML
 * to build the next level. There is no second fetch pass and no return
 * value - the caller just consumes the yielded page chunks.
 */
final class URLCollectorTest extends TestCase
{
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
            'sp_parallel_requests' => 1,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [$this->urlPrefix],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ], $overrides);

        $helper = new PipelineConfigHelper($ctx, $logger);
        $crawlerConfig = new PipelineConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, []);

        return new URLCollector($crawlerConfig, $urlNormalizer, $logger, $robotsTxtChecker, $fetcher);
    }

    /**
     * Flattens the yielded chunks into a flat list of fetched URLs.
     *
     * @return list<string>
     */
    private function fetchedUrls(\Generator $generator): array
    {
        $urls = [];
        foreach ($generator as $chunk) {
            foreach ($chunk as $page) {
                $urls[] = $page['url'];
            }
        }

        return $urls;
    }

    public function testYieldsFetchedStartPage(): void
    {
        $html = '<div id="content"></div>';

        $collector = $this->createCollector(
            $this->stubFetcher([$this->urlPrefix => $html]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
        );

        $chunks = iterator_to_array($collector->collect());

        $this->assertSame(
            [[['url' => $this->urlPrefix, 'html' => $html]]],
            $chunks,
        );
    }

    public function testFollowsLinksAcrossDepthAndFetchesEveryPageOnce(): void
    {
        $indexHtml = '<div id="content"><a href="https://example.com/section">Section</a></div>';
        $sectionHtml = '<div id="content"><a href="https://example.com/article">Article</a></div>';
        $articleHtml = '<div id="content"></div>';

        $collector = $this->createCollector(
            $this->stubFetcher([
                $this->urlPrefix => $indexHtml,
                'https://example.com/section' => $sectionHtml,
                'https://example.com/article' => $articleHtml,
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
            ['sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 1]]],
        );

        // depth 1 fetches levels 0..2: start, section, article - each once.
        $this->assertSame(
            [$this->urlPrefix, 'https://example.com/section', 'https://example.com/article'],
            $this->fetchedUrls($collector->collect()),
        );
    }

    public function testEachUrlIsFetchedOnlyOnce(): void
    {
        $startHtml = '<div id="content">'
            . '<a href="https://example.com/page-a">A</a>'
            . '<a href="https://example.com/page-b">B</a>'
            . '</div>';
        $pageAHtml = '<div id="content"><a href="https://example.com/page-b">B again</a></div>';

        $collector = $this->createCollector(
            $this->stubFetcher([
                $this->urlPrefix => $startHtml,
                'https://example.com/page-a' => $pageAHtml,
                'https://example.com/page-b' => '<div id="content"></div>',
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
            ['sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 2]]],
        );

        $urls = $this->fetchedUrls($collector->collect());

        // page-b is discovered from both the start page and page-a, but fetched once.
        $this->assertSame(1, array_count_values($urls)['https://example.com/page-b']);
        $this->assertSame(
            [$this->urlPrefix, 'https://example.com/page-a', 'https://example.com/page-b'],
            $urls,
        );
    }

    public function testRespectRobotsTxtFiltersDiscoveredLinks(): void
    {
        $html = '<div id="content">'
            . '<a href="https://example.com/page1">Page 1</a>'
            . '<a href="https://example.com/page2">Page 2</a>'
            . '</div>';

        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->expects($this->once())
            ->method('filterAllowed')
            ->willReturn(['https://example.com/page1']);

        $collector = $this->createCollector(
            $this->stubFetcher([
                $this->urlPrefix => $html,
                'https://example.com/page1' => '<div id="content"></div>',
                'https://example.com/page2' => '<div id="content"></div>',
            ]),
            $this->createStub(LoggerInterface::class),
            $robotsTxtChecker,
            ['sp_respect_robots_txt' => true],
        );

        // page2 is filtered out by robots.txt, so it is never fetched.
        $this->assertSame(
            [$this->urlPrefix, 'https://example.com/page1'],
            $this->fetchedUrls($collector->collect()),
        );
    }

    public function testStopsAtMaxTeaser(): void
    {
        $html = '<div id="content">'
            . '<a href="https://example.com/page1">1</a>'
            . '<a href="https://example.com/page2">2</a>'
            . '<a href="https://example.com/page3">3</a>'
            . '</div>';

        $collector = $this->createCollector(
            $this->stubFetcher([
                $this->urlPrefix => $html,
                'https://example.com/page1' => '<div id="content"></div>',
                'https://example.com/page2' => '<div id="content"></div>',
                'https://example.com/page3' => '<div id="content"></div>',
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
            ['sp_max_teaser' => 2],
        );

        // start page + one discovered page = 2, then the limit stops the crawl.
        $this->assertCount(2, $this->fetchedUrls($collector->collect()));
    }

    public function testForcedArticleUrlsAreAlwaysFetched(): void
    {
        $forced = 'https://example.com/forced';

        $collector = $this->createCollector(
            $this->stubFetcher([
                $forced => '<div id="content"></div>',
                $this->urlPrefix => '<div id="content"></div>',
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
            [
                'sp_forced_article_urls' => [$forced],
                'sp_max_teaser' => 1,
            ],
        );

        $urls = $this->fetchedUrls($collector->collect());

        $this->assertContains($forced, $urls);
        $this->assertContains($this->urlPrefix, $urls);
    }

    /**
     * A forced URL that is also a normal (linked) page must still be crawled,
     * so the pages it links to are discovered - not just fetched flat.
     */
    public function testForcedUrlThatIsAlsoACategoryStillGetsCrawled(): void
    {
        $category = 'https://example.com/category';
        $startHtml = '<div id="content"><a href="' . $category . '">Category</a></div>';
        $categoryHtml = '<div id="content"><a href="https://example.com/article">Article</a></div>';

        $collector = $this->createCollector(
            $this->stubFetcher([
                $this->urlPrefix => $startHtml,
                $category => $categoryHtml,
                'https://example.com/article' => '<div id="content"></div>',
            ]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
            [
                'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 1]],
                'sp_forced_article_urls' => [$category],
            ],
        );

        // The article behind the forced category page must be discovered.
        $this->assertContains('https://example.com/article', $this->fetchedUrls($collector->collect()));
    }

    public function testBrokenLinkIsIgnored(): void
    {
        $html = '<div id="content"><a href="javascript:void(0)">Broken</a></div>';

        $collector = $this->createCollector(
            $this->stubFetcher([$this->urlPrefix => $html]),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
        );

        // Only the start page is fetched; the broken link yields no next level.
        $this->assertSame([$this->urlPrefix], $this->fetchedUrls($collector->collect()));
    }

    public function testEmptyHtmlContentYieldsPageButNoLinks(): void
    {
        $collector = $this->createCollector(
            $this->stubFetcher([$this->urlPrefix => '']),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
        );

        $this->assertSame([$this->urlPrefix], $this->fetchedUrls($collector->collect()));
    }

    public function testFetcherFailurePropagatesWhileIterating(): void
    {
        $fetcher = $this->createStub(Fetcher::class);
        $fetcher->method('fetchUrls')->willThrowException(new \RuntimeException('Connection failed'));

        $collector = $this->createCollector(
            $fetcher,
            $this->createStub(LoggerInterface::class),
            $this->createStub(RobotsTxtCheckerInterface::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Connection failed');

        iterator_to_array($collector->collect());
    }
}
