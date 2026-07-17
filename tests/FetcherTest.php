<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Domain\Crawler\Steps\Fetcher;
use Atoolo\Crawler\Domain\Crawler\Ports\RequestExecutorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class FetcherTest extends TestCase
{
    /** @var MockObject&LoggerInterface */
    private LoggerInterface $logger;
    private Fetcher $fetcher;
    /** @var MockObject&RequestExecutorInterface */
    private $requestExecutorInterfaceMock;

    /**
     * The setUp() method is automatically called by PHPUnit before each test. It independently creates
     * and prepares the necessary objects for each test case, ensuring a clean and consistent starting state.
     */
    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestExecutorInterfaceMock = $this->createMock(RequestExecutorInterface::class);

        $this->fetcher = new Fetcher(
            $this->requestExecutorInterfaceMock,
            $this->logger
        );
    }

    /**
     * Test that exceptions when starting requests are logged and the retry mechanism is used.
     */
    public function testFetchUrlsReturnsEmptyWhenExecutorReturnsNull(): void
    {
        $url = 'https://bad.example.com';

        $this->requestExecutorInterfaceMock
            ->expects($this->once())
            ->method('request')
            ->with($url)
            ->willReturn(null);

        $this->logger->expects($this->never())->method('error');

        $result = $this->fetcher->fetchUrls([$url]);

        $this->assertSame([], $result);
    }

    /**
     * Test that exceptions when retrieving content are logged and skipped.
     */
    public function testFetchUrlsLogsErrorWhenContentRetrievalFails(): void
    {
        $url = 'https://example.com/fail';

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willThrowException(new \RuntimeException('Timeout'));

        $this->requestExecutorInterfaceMock
            ->expects($this->once())
            ->method('request')
            ->with($url)
            ->willReturn($response);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to retrieve content'),
                $this->arrayHasKey('exception')
            );

        $result = $this->fetcher->fetchUrls([$url]);

        $this->assertSame([], $result);
    }

    /**
     * Test that non-2xx responses are skipped without logging.
     */
    public function testFetchUrlsSkipsNon2xxResponsesWithoutLogging(): void
    {
        $url = 'https://example.com/notfound';

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $this->requestExecutorInterfaceMock
            ->expects($this->once())
            ->method('request')
            ->with($url)
            ->willReturn($response);

        $this->logger->expects($this->never())->method('error');

        $result = $this->fetcher->fetchUrls([$url]);

        $this->assertSame([], $result);
    }
}
