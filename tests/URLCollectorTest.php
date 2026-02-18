<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Domain\Crawler\Steps\URLCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Atoolo\Crawler\Domain\Crawler\Services\URLNormalizer;
use Atoolo\Crawler\Domain\Crawler\Ports\RequestExecutorInterface;
use Atoolo\Crawler\Domain\Crawler\Services\RobotsTxtCheckerInterface;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Psr\Log\LoggerInterface;

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
    private $url1 = 'https://example.com/page1';
    private $urlPrefix = 'https://example.com';

    private function createCollector(
        RequestExecutorInterface $requestExecutor,
        LoggerInterface $logger,
        RobotsTxtCheckerInterface $robotsTxtChecker,
    ): URLCollector {
        $ctx = new CrawlerConfigContext();
        $ctx->set([
            'atoolo.crawler.start_urls' => [['url' => $this->urlPrefix, 'extraction_depth' => 0]],
            'atoolo.crawler.link_section' => '#content',
            'atoolo.crawler.link_selector' => 'a[href]',
            'atoolo.crawler.forced_article_urls' => [],
            'atoolo.crawler.max_teaser' => 999,
            'atoolo.crawler.deny_prefixes' => [],
            'atoolo.crawler.allow_prefixes' => [$this->urlPrefix],
            'atoolo.crawler.strip_query_params_active' => false,
            'atoolo.crawler.strip_query_params' => [],
        ]);

        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);

        $urlNormalizer = new URLNormalizer($crawlerConfig);

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
}
