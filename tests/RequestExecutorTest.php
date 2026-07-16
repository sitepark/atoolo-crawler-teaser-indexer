<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Ports\RequestExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RequestExecutorTest extends TestCase
{
    private function makeConfig(array $overrides = []): CrawlerConfig
    {
        $ctx = new CrawlerConfigContext(array_merge([
            'sp_user_agent' => 'TestBot/1.0',
            'sp_max_retry' => 3,
            'sp_delay_ms' => 0,
            'sp_backoff_ms' => 100,
        ], $overrides));
        $logger = $this->createStub(LoggerInterface::class);
        $helper = new CrawlerConfigHelper($ctx, $logger);

        return new CrawlerConfig($helper);
    }

    private function makeHttpClient(ResponseInterface $response): HttpClientInterface
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('withOptions')->willReturnSelf();
        $httpClient->method('request')->willReturn($response);

        return $httpClient;
    }

    public function testRequestReturnsResponseOnSuccess(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->makeHttpClient($response);
        $config = $this->makeConfig();
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([], $config, $httpClient, $logger);
        $result = $executor->request('https://example.com/');

        $this->assertSame($response, $result);
    }

    public function testRequestReturnsNullAfterAllRetriesOnTransportException(): void
    {
        $transportException = new class ('timeout') extends \Exception implements TransportExceptionInterface {
        };

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('withOptions')->willReturnSelf();
        $httpClient->method('request')->willThrowException($transportException);

        $config = $this->makeConfig(['sp_max_retry' => 2, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([], $config, $httpClient, $logger);
        $result = $executor->request('https://example.com/');

        $this->assertNull($result);
    }

    public function testRequestRetriesOnRetryableStatusThenSucceeds(): void
    {
        $failResponse = $this->createStub(ResponseInterface::class);
        $failResponse->method('getStatusCode')->willReturn(500);
        $failResponse->method('getHeaders')->willReturn([]);

        $successResponse = $this->createStub(ResponseInterface::class);
        $successResponse->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('withOptions')->willReturnSelf();
        $httpClient->method('request')->willReturnOnConsecutiveCalls($failResponse, $successResponse);

        $config = $this->makeConfig(['sp_max_retry' => 3, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([500], $config, $httpClient, $logger);
        $result = $executor->request('https://example.com/');

        $this->assertSame($successResponse, $result);
    }

    public function testRequestWithNonRetryableStatusReturnsImmediately(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $httpClient = $this->makeHttpClient($response);
        $config = $this->makeConfig(['sp_max_retry' => 3]);
        $logger = $this->createStub(LoggerInterface::class);

        // 404 is not in retryStatusCodes, so it should return after first attempt
        $executor = new RequestExecutor([500, 503], $config, $httpClient, $logger);
        $result = $executor->request('https://example.com/');

        $this->assertSame($response, $result);
    }

    public function testThrottleDoesNotThrowForValidUrl(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('withOptions')->willReturnSelf();

        $config = $this->makeConfig(['sp_delay_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);
        $executor = new RequestExecutor([], $config, $httpClient, $logger);

        // Should not throw
        $executor->throttle('https://example.com/page');
        $executor->throttle('https://example.com/page2'); // second call to same host
        $this->assertTrue(true); // reached here without exception
    }

    public function testThrottleWithInvalidUrlReturnsEarly(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('withOptions')->willReturnSelf();

        $config = $this->makeConfig();
        $logger = $this->createStub(LoggerInterface::class);
        $executor = new RequestExecutor([], $config, $httpClient, $logger);

        // 'not-a-url' has no host, throttle should return early without error
        $executor->throttle('not-a-url');
        $this->assertTrue(true);
    }

    public function testRequestWithRetryAfterHeaderUsesHeaderDelay(): void
    {
        $failResponse = $this->createStub(ResponseInterface::class);
        $failResponse->method('getStatusCode')->willReturn(429);
        $failResponse->method('getHeaders')->willReturn(['retry-after' => ['0']]);

        $successResponse = $this->createStub(ResponseInterface::class);
        $successResponse->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('withOptions')->willReturnSelf();
        $httpClient->method('request')->willReturnOnConsecutiveCalls($failResponse, $successResponse);

        $config = $this->makeConfig(['sp_max_retry' => 3, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([429], $config, $httpClient, $logger);
        $result = $executor->request('https://example.com/');

        $this->assertSame($successResponse, $result);
    }

    public function testThrottleSleedsWhenSecondCallIsTooFastForSameHost(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('withOptions')->willReturnSelf();

        // 50ms delay: second call within 50ms of first → usleep is triggered
        $config = $this->makeConfig(['sp_delay_ms' => 50]);
        $logger = $this->createStub(LoggerInterface::class);
        $executor = new RequestExecutor([], $config, $httpClient, $logger);

        $start = microtime(true);
        $executor->throttle('https://example.com/page');
        $executor->throttle('https://example.com/page'); // same URL / same host → triggers sleep
        $elapsed = microtime(true) - $start;

        // At least some throttle delay was applied (50ms = 0.05s)
        $this->assertGreaterThanOrEqual(0.04, $elapsed);
    }
}
