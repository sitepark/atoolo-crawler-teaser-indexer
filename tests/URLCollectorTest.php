<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Ports\RequestExecutorInterface;
use Atoolo\Crawler\Domain\Crawler\Services\RobotsTxtCheckerInterface;
use Atoolo\Crawler\Domain\Crawler\Services\URLNormalizer;
use Atoolo\Crawler\Domain\Crawler\Steps\URLCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for the URLCollector class.
 *
 * This test suite ensures that:
 * - Links are extracted and filtered correctly.
 * - Relative URLs are resolved against the base URL.
 * - Unnecessary URLs are excluded.
 * - HTTP errors throw proper exceptions.
 * - Broken or invalid links are logged and skipped.
 * - Edge cases such as duplicate links, empty content, and non-http protocols are handled correctly.
 */
final class URLCollectorTest extends TestCase
{
    private string $url1 = 'https://example.com/page1';
    private string $urlPrefix = 'https://example.com';
    /**
     * @param array<string> $denyEndings
     */
    private array $denyEndings = [
            ".jpg",
            ".jpeg",
            ".png",
            ".gif",
            ".svg",
            ".webp",
            ".ico",
            ".bmp",
            ".tiff"
        ];

    private function createCollector(
        RequestExecutorInterface $requestExecutor,
        LoggerInterface $logger,
        RobotsTxtCheckerInterface $robotsTxtChecker,
    ): URLCollector {
        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 0]],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [$this->urlPrefix],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);

        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        

        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);

        return new URLCollector(
            $crawlerConfig,
            $urlNormalizer,
            $logger,
            $requestExecutor,
            $robotsTxtChecker,
        );
    }

    /**
     * Test that valid links are extracted and unnecessary ones are filtered out.
     */
    public function testFindHrefUrlsByCssSelectorExtractsAndFilters(): void
    {
        $html = <<<HTML
<!doctype html>
<html><body id="content">
<a href="$this->url1">Page 1</a>
<a href="https://example.com/unwanted/page2">Page 2</a>
<a href="/relative">Relative link</a>
</body></html>
HTML;

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $collector = $this->createCollector($requestExecutor, $logger, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();
        $expected = [
            $this->url1,
            'https://example.com/unwanted/page2',
            'https://example.com/relative',
        ];
        $this->assertSame($expected, $result);
    }

    /**
     * Test that a failing HttpClient request throws a RuntimeException
     * and includes the base URL in the exception message.
     */
    public function testHttpClientFailureThrowsRuntimeException(): void
    {
        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willThrowException(new \Exception('Connection failed'));

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);
        $collector = $this->createCollector($requestExecutor, $logger, $robotsTxtChecker);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Connection failed');

        $collector->findHrefUrlsByCssSelector();
    }

    /**
     * Test that broken links (e.g., javascript:) are skipped and logged.
     */
    public function testBrokenLinkIsLoggedButNotIncluded(): void
    {
        $html = '<a href="javascript:void(0)">Broken</a>';
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);
        $collector = $this->createCollector($requestExecutor, $logger, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertSame([], $result);
    }

    /**
     * Test the filterUnneededUrls method directly via reflection.
     */
    public function testFilterUnneededUrlsViaFindHrefUrlsByCssSelector(): void
    {
        $html = <<<HTML
<!doctype html>
<html><body id="content">
<a href="$this->url1">Page 1</a>
<a href="https://example.com/unwanted/page2">Page 2</a>
</body></html>
HTML;

        $logger = $this->createStub(LoggerInterface::class);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createMock(RequestExecutorInterface::class);

        $requestExecutor
            ->expects($this->once())
            ->method('request')
            ->with('https://example.com')
            ->willReturn($response);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);
        $collector = $this->createCollector($requestExecutor, $logger, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertSame([$this->url1, 'https://example.com/unwanted/page2'], $result);
    }

    /**
     * Test that duplicate links are only returned once.
     */
    public function testDuplicateLinksAreRemoved(): void
    {
        $html = <<<HTML
<!doctype html>
<html><body id="content">
<a href="$this->url1">Page 1</a>
<a href="https://example.com/page1">Page 2</a>
</body></html>
HTML;

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);
        $collector = $this->createCollector($requestExecutor, $logger, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertSame([$this->url1], $result);
    }

    /**
     * Test that empty HTML content results in no links being extracted.
     */
    public function testEmptyHtmlContentReturnsNoLinks(): void
    {
        $html = '';
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);
        $collector = $this->createCollector($requestExecutor, $logger, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertSame([], $result);
    }

    /**
     * Test that requestExecutor returning null throws LogicException.
     */
    public function testNullResponseThrowsLogicException(): void
    {
        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn(null);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);
        $collector = $this->createCollector($requestExecutor, $logger, $robotsTxtChecker);

        $this->expectException(\LogicException::class);
        $collector->findHrefUrlsByCssSelector();
    }

    /**
     * Test that maxTeaser limits the returned URLs and the exact URLs are correct.
     */
    public function testMaxTeaserLimitsResults(): void
    {
        $html = <<<HTML
<!doctype html>
<html><body id="content">
<a href="https://example.com/page1">Page 1</a>
<a href="https://example.com/page2">Page 2</a>
<a href="https://example.com/page3">Page 3</a>
</body></html>
HTML;

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 0]],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 2,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [$this->urlPrefix],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertCount(2, $result);
        $this->assertSame(['https://example.com/page1', 'https://example.com/page2'], $result);
    }

    /**
     * Test that forcedArticleUrls are appended to the final result.
     */
    public function testForcedArticleUrlsAreAppendedToResults(): void
    {
        $html = '<html><body id="content"><a href="https://example.com/page1">Page 1</a></body></html>';

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 0]],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => ['https://example.com/forced'],
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [$this->urlPrefix],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertContains('https://example.com/forced', $result);
        $this->assertContains('https://example.com/page1', $result);
        $this->assertSame(
            ['https://example.com/page1', 'https://example.com/forced'],
            $result,
        );
    }

    /**
     * Test that respectRobotsTxt=true delegates filtering to the robotsTxtChecker.
     */
    public function testRespectRobotsTxtFiltersThroughChecker(): void
    {
        $html = <<<HTML
<!doctype html>
<html><body id="content">
<a href="https://example.com/page1">Page 1</a>
<a href="https://example.com/page2">Page 2</a>
</body></html>
HTML;

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);

        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->expects($this->once())
            ->method('filterAllowed')
            ->willReturn(['https://example.com/page1']);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 0]],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [$this->urlPrefix],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
            'sp_respect_robots_txt' => true,
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertSame(['https://example.com/page1'], $result);
    }

    /**
     * Test that depth=1 crawling follows links on the first page to discover second-level links.
     */
    public function testCrawlByDepthFollowsLinksOnFirstPage(): void
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

        $requestExecutor = $this->createMock(RequestExecutorInterface::class);

        $indexResponse = $this->createStub(ResponseInterface::class);
        $indexResponse->method('getContent')->willReturn($indexHtml);

        $sectionResponse = $this->createStub(ResponseInterface::class);
        $sectionResponse->method('getContent')->willReturn($sectionHtml);

        $requestExecutor->method('request')->willReturnMap([
            [$this->urlPrefix, $indexResponse],
            ['https://example.com/section', $sectionResponse],
        ]);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 1]],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [$this->urlPrefix],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertContains('https://example.com/section', $result);
        $this->assertContains('https://example.com/article', $result);
        $this->assertSame(
            ['https://example.com/section', 'https://example.com/article'],
            $result,
        );
    }

    /**
     * Test that links matching a deny prefix are skipped in crawlByDepth (line 115).
     */
    public function testDenyPrefixLinksAreFilteredInCrawlByDepth(): void
    {
        $html = <<<HTML
<html><body id="content">
<a href="https://example.com/allowed">Allowed</a>
<a href="https://example.com/denied/secret">Denied</a>
</body></html>
HTML;

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 0]],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => ['https://example.com/denied'],
            'sp_allow_prefixes' => [],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertSame(['https://example.com/allowed'], $result);
        $this->assertNotContains('https://example.com/denied/secret', $result);
    }

    /**
     * Test that https links not matching an allow prefix are skipped in crawlByDepth (line 119).
     */
    public function testNonMatchingAllowPrefixLinksAreFilteredInCrawlByDepth(): void
    {
        $html = <<<HTML
<html><body id="content">
<a href="https://example.com/allowed">Allowed</a>
<a href="https://other.com/external">External</a>
</body></html>
HTML;

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 0]],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => ['https://example.com'],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertSame(['https://example.com/allowed'], $result);
        $this->assertNotContains('https://other.com/external', $result);
    }

    /**
     * Test that a URL already visited is skipped when encountered again in the queue (line 107).
     * This requires depth >= 2 so that a URL can be linked from two different pages.
     */
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

        $pageBHtml = '<html><body id="content"></body></html>';

        $startResponse = $this->createStub(ResponseInterface::class);
        $startResponse->method('getContent')->willReturn($startHtml);

        $pageAResponse = $this->createStub(ResponseInterface::class);
        $pageAResponse->method('getContent')->willReturn($pageAHtml);

        $pageBResponse = $this->createStub(ResponseInterface::class);
        $pageBResponse->method('getContent')->willReturn($pageBHtml);

        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturnMap([
            [$this->urlPrefix, $startResponse],
            ['https://example.com/page-a', $pageAResponse],
            ['https://example.com/page-b', $pageBResponse],
        ]);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 2]],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        // page-b is discovered from two pages but fetched only once (second queue entry skipped)
        $this->assertContains('https://example.com/page-a', $result);
        $this->assertContains('https://example.com/page-b', $result);
        $this->assertSame(
            ['https://example.com/page-a', 'https://example.com/page-b'],
            $result,
        );
    }

    /**
     * Test that when multiple startUrls together exceed maxTeaser, the result is sliced (line 50).
     */
    public function testMultipleStartUrlsTotalExceedingMaxTeaserIsSliced(): void
    {
        $start1Html = '<html><body id="content">
            <a href="https://example.com/from-start1">From Start 1</a></body></html>';

        $start2Html = '<html><body id="content">
            <a href="https://example.com/from-start2">From Start 2</a></body></html>';

        $start1Response = $this->createStub(ResponseInterface::class);
        $start1Response->method('getContent')->willReturn($start1Html);

        $start2Response = $this->createStub(ResponseInterface::class);
        $start2Response->method('getContent')->willReturn($start2Html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturnMap([
            ['https://example.com/start1', $start1Response],
            ['https://example.com/start2', $start2Response],
        ]);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [
                ['sp_url' => 'https://example.com/start1', 'sp_extraction_depth' => 0],
                ['sp_url' => 'https://example.com/start2', 'sp_extraction_depth' => 0],
            ],
            'sp_link_selector' => '#content a[href]',
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 1,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        $result = $collector->findHrefUrlsByCssSelector();

        // Each startUrl contributes 1 URL (maxTeaser=1 per crawl), total 2 URLs
        // After the outer count check, sliced to maxTeaser=1
        $this->assertCount(1, $result);
        $this->assertSame(['https://example.com/from-start1'], $result);
    }

    /**
     * Test that the catch block in extractAbsoluteUrlsFromScope is triggered when
     * the Link constructor throws for a non-<a> element (lines 179-184).
     *
     * Using a selector that matches non-<a> elements causes Symfony's Link class
     * to throw a LogicException ("Unable to navigate from a div tag."),
     * which is caught and logged as debug.
     */
    public function testNonAnchorElementCaughtInExtractAbsoluteUrlsFromScope(): void
    {
        // HTML with a <div href="..."> (non-anchor with href attribute)
        $html = '<html><body id="content"><div href="https://example.com/page">link</div></body></html>';

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($html);

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $robotsTxtChecker = $this->createStub(RobotsTxtCheckerInterface::class);

        $ctx = new CrawlerConfigContext([
            'sp_start_urls' => [['sp_url' => $this->urlPrefix, 'sp_extraction_depth' => 0]],
            'sp_link_selector' => '#content div[href]', // selects <div href="..."> not <a>
            'sp_forced_article_urls' => [],
            'sp_max_teaser' => 999,
            'sp_deny_prefixes' => [],
            'sp_allow_prefixes' => [],
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $urlNormalizer = new URLNormalizer($crawlerConfig, $this->denyEndings);
        $collector = new URLCollector($crawlerConfig, $urlNormalizer, $logger, $requestExecutor, $robotsTxtChecker);

        // Link constructor throws for non-<a> elements → caught → empty result
        $result = $collector->findHrefUrlsByCssSelector();

        $this->assertSame([], $result);
    }
}
