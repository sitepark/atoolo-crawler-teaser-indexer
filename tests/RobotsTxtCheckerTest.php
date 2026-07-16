<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Ports\RequestExecutorInterface;
use Atoolo\Crawler\Domain\Crawler\Services\RobotsTxtChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RobotsTxtCheckerTest extends TestCase
{
    private function makeChecker(
        array $config,
        RequestExecutorInterface $requestExecutor,
        LoggerInterface $logger,
    ): RobotsTxtChecker {
        $ctx = new CrawlerConfigContext($config);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        return new RobotsTxtChecker($crawlerConfig, $requestExecutor, $logger);
    }

    public function testReturnsAllUrlsWhenNoRobotsUrlConfigured(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->expects($this->never())->method('request');

        $checker = $this->makeChecker([], $requestExecutor, $logger);

        $urls = ['https://example.com/page1', 'https://example.com/page2'];
        $result = $checker->filterAllowed($urls);

        $this->assertSame($urls, $result);
    }

    public function testReturnsAllUrlsWhenRobotsUrlIsEmptyString(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->expects($this->never())->method('request');

        $checker = $this->makeChecker(['sp_robots_url' => ''], $requestExecutor, $logger);

        $urls = ['https://example.com/page1'];
        $result = $checker->filterAllowed($urls);

        $this->assertSame($urls, $result);
    }

    public function testFiltersUrlsDisallowedByRobotsTxt(): void
    {
        $robotsTxtContent = <<<ROBOTS
User-agent: *
Disallow: /private/
ROBOTS;

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($robotsTxtContent);

        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $checker = $this->makeChecker(
            ['sp_robots_url' => 'https://example.com/robots.txt'],
            $requestExecutor,
            $logger,
        );

        $urls = [
            'https://example.com/page',
            'https://example.com/private/secret',
        ];
        $result = $checker->filterAllowed($urls);

        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testAllowsAllUrlsWhenRobotsTxtPermitsEverything(): void
    {
        $robotsTxtContent = <<<ROBOTS
User-agent: *
Allow: /
ROBOTS;

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($robotsTxtContent);

        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $checker = $this->makeChecker(
            ['sp_robots_url' => 'https://example.com/robots.txt'],
            $requestExecutor,
            $logger,
        );

        $urls = ['https://example.com/page1', 'https://example.com/page2'];
        $result = $checker->filterAllowed($urls);

        $this->assertSame($urls, $result);
    }

    public function testReturnsAllUrlsWhenRobotsRequestThrowsException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('robots.txt could not be read, defaulting to allow');

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willThrowException(new \RuntimeException('Connection refused'));

        $checker = $this->makeChecker(
            ['sp_robots_url' => 'https://example.com/robots.txt'],
            $requestExecutor,
            $logger,
        );

        $urls = ['https://example.com/page1', 'https://example.com/page2'];
        $result = $checker->filterAllowed($urls);

        $this->assertSame($urls, $result);
    }

    public function testRobotsRequestIsCachedAndCalledOnlyOnce(): void
    {
        $robotsTxtContent = "User-agent: *\nAllow: /";

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn($robotsTxtContent);

        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $checker = $this->makeChecker(
            ['sp_robots_url' => 'https://example.com/robots.txt'],
            $requestExecutor,
            $logger,
        );

        $checker->filterAllowed(['https://example.com/page1']);
        $checker->filterAllowed(['https://example.com/page2']);
    }

    public function testReturnsAllUrlsWhenRobotsRequestReturnsNull(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn(null);

        $checker = $this->makeChecker(
            ['sp_robots_url' => 'https://example.com/robots.txt'],
            $requestExecutor,
            $logger,
        );

        $urls = ['https://example.com/page1'];
        $result = $checker->filterAllowed($urls);

        $this->assertSame($urls, $result);
    }

    public function testDeduplicatesResultUrls(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->expects($this->never())->method('request');

        $checker = $this->makeChecker([], $requestExecutor, $logger);

        $urls = ['https://example.com/page', 'https://example.com/page'];
        $result = $checker->filterAllowed($urls);

        $this->assertSame(['https://example.com/page'], $result);
    }
}
