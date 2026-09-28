<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Ports\RequestExecutorInterface;
use Atoolo\CrawlerIndexer\Pipeline\Collector\RobotsTxtChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RobotsTxtCheckerTest extends TestCase
{
    private PipelineConfig $config;

    private function makeChecker(
        array $config,
        RequestExecutorInterface $requestExecutor,
        LoggerInterface $logger,
    ): RobotsTxtChecker {
        $ctx = $config;
        $helper = new PipelineConfigHelper($ctx, $logger);
        $this->config = new PipelineConfig($helper);

        return new RobotsTxtChecker($requestExecutor, $logger);
    }

    public function testReturnsAllUrlsWhenNoRobotsUrlConfigured(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->expects($this->never())->method('request');

        $checker = $this->makeChecker([], $requestExecutor, $logger);

        $urls = ['https://example.com/page1', 'https://example.com/page2'];
        $result = $checker->filterAllowed($urls, $this->config);

        $this->assertSame($urls, $result);
    }

    public function testReturnsAllUrlsWhenRobotsUrlIsEmptyString(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->expects($this->never())->method('request');

        $checker = $this->makeChecker(['sp_robots_url' => ''], $requestExecutor, $logger);

        $urls = ['https://example.com/page1'];
        $result = $checker->filterAllowed($urls, $this->config);

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
        $result = $checker->filterAllowed($urls, $this->config);

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
        $result = $checker->filterAllowed($urls, $this->config);

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
        $result = $checker->filterAllowed($urls, $this->config);

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

        $checker->filterAllowed(['https://example.com/page1'], $this->config);
        $checker->filterAllowed(['https://example.com/page2'], $this->config);
    }

    /**
     * The checker lives for the whole worker process; reset() between runs
     * makes every run read the current robots.txt.
     */
    public function testResetForgetsCachedRobotsTxt(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn("User-agent: *\nAllow: /");

        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->expects($this->exactly(2))
            ->method('request')
            ->willReturn($response);

        $checker = $this->makeChecker(
            ['sp_robots_url' => 'https://example.com/robots.txt'],
            $requestExecutor,
            $this->createStub(LoggerInterface::class),
        );

        $checker->filterAllowed(['https://example.com/page1'], $this->config);
        $checker->reset();
        $checker->filterAllowed(['https://example.com/page1'], $this->config);
    }

    /**
     * The config is passed per call, so one checker instance serves sites with
     * different user agents.
     */
    public function testUsesUserAgentOfTheConfigPassedPerCall(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getContent')->willReturn("User-agent: BadBot\nDisallow: /");

        $requestExecutor = $this->createStub(RequestExecutorInterface::class);
        $requestExecutor->method('request')->willReturn($response);

        $logger = $this->createStub(LoggerInterface::class);
        $checker = new RobotsTxtChecker($requestExecutor, $logger);
        $url = ['https://example.com/page'];

        $badBot = new PipelineConfig(new PipelineConfigHelper([
            'sp_robots_url' => 'https://example.com/robots.txt',
            'sp_user_agent' => 'BadBot',
        ], $logger));
        $goodBot = new PipelineConfig(new PipelineConfigHelper([
            'sp_robots_url' => 'https://example.com/robots.txt',
            'sp_user_agent' => 'GoodBot',
        ], $logger));

        $this->assertSame([], $checker->filterAllowed($url, $badBot));
        $this->assertSame($url, $checker->filterAllowed($url, $goodBot));
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
        $result = $checker->filterAllowed($urls, $this->config);

        $this->assertSame($urls, $result);
    }

    public function testDeduplicatesResultUrls(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $requestExecutor = $this->createMock(RequestExecutorInterface::class);
        $requestExecutor->expects($this->never())->method('request');

        $checker = $this->makeChecker([], $requestExecutor, $logger);

        $urls = ['https://example.com/page', 'https://example.com/page'];
        $result = $checker->filterAllowed($urls, $this->config);

        $this->assertSame(['https://example.com/page'], $result);
    }
}
