<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Ports\RequestExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RequestExecutorTest extends TestCase
{
    private function makeConfig(array $overrides = []): PipelineConfig
    {
        $ctx = array_merge([
            'sp_user_agent' => 'TestBot/1.0',
            'sp_max_retry' => 3,
            'sp_delay_ms' => 0,
            'sp_backoff_ms' => 100,
        ], $overrides);
        $logger = $this->createStub(LoggerInterface::class);
        $helper = new PipelineConfigHelper($ctx, $logger);

        return new PipelineConfig($helper);
    }

    private function makeHttpClient(ResponseInterface $response): HttpClientInterface
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
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

        $executor = new RequestExecutor([], $httpClient, $logger);
        $result = $executor->request('https://example.com/', $config);

        $this->assertSame($response, $result);
    }

    public function testRequestReturnsNullAfterAllRetriesOnTransportException(): void
    {
        $transportException = new class ('timeout') extends \Exception implements TransportExceptionInterface {};

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willThrowException($transportException);

        $config = $this->makeConfig(['sp_max_retry' => 2, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([], $httpClient, $logger);
        $result = $executor->request('https://example.com/', $config);

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
        $httpClient->method('request')->willReturnOnConsecutiveCalls($failResponse, $successResponse);

        $config = $this->makeConfig(['sp_max_retry' => 3, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([500], $httpClient, $logger);
        $result = $executor->request('https://example.com/', $config);

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
        $executor = new RequestExecutor([500, 503], $httpClient, $logger);
        $result = $executor->request('https://example.com/', $config);

        $this->assertSame($response, $result);
    }

    public function testThrottleDoesNotThrowForValidUrl(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);

        $config = $this->makeConfig(['sp_delay_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);
        $executor = new RequestExecutor([], $httpClient, $logger);

        // Should not throw
        $executor->throttle('https://example.com/page', $config);
        $executor->throttle('https://example.com/page2', $config); // second call to same host
        $this->assertTrue(true); // reached here without exception
    }

    public function testThrottleWithInvalidUrlReturnsEarly(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);

        $config = $this->makeConfig();
        $logger = $this->createStub(LoggerInterface::class);
        $executor = new RequestExecutor([], $httpClient, $logger);

        // 'not-a-url' has no host, throttle should return early without error
        $executor->throttle('not-a-url', $config);
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
        $httpClient->method('request')->willReturnOnConsecutiveCalls($failResponse, $successResponse);

        $config = $this->makeConfig(['sp_max_retry' => 3, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([429], $httpClient, $logger);
        $result = $executor->request('https://example.com/', $config);

        $this->assertSame($successResponse, $result);
    }

    public function testRequestChunkReturnsResponsesKeyedByUrl(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->makeHttpClient($response);
        $config = $this->makeConfig(['sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([], $httpClient, $logger);
        $result = $executor->requestChunk([
            'https://example.com/a',
            'https://example.com/b',
        ], $config);

        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b'],
            array_keys($result),
        );
        $this->assertSame($response, $result['https://example.com/a']);
        $this->assertSame($response, $result['https://example.com/b']);
    }

    public function testRequestChunkSkipsUrlThatCannotBeRequested(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(
            static function (string $method, string $url) use ($response): ResponseInterface {
                if (str_contains($url, ':99999')) {
                    throw new \InvalidArgumentException('Malformed URL');
                }

                return $response;
            },
        );

        $executor = new RequestExecutor([], $httpClient, $this->createStub(LoggerInterface::class));
        $result = $executor->requestChunk(['https://example.com:99999/x', 'https://example.com/ok'], $this->makeConfig());

        $this->assertSame(['https://example.com/ok'], array_keys($result));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function retryAfterProvider(): iterable
    {
        yield 'small value is used' => ['5', 5_000];
        yield 'one hour is capped' => ['3600', 120_000];
        yield 'overflowing value is capped' => ['99999999999999999999', 120_000];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('retryAfterProvider')]
    public function testRetryAfterIsCapped(string $retryAfter, int $expectedMs): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getHeaders')->willReturn(['retry-after' => [$retryAfter]]);

        $executor = new RequestExecutor([], $this->createStub(HttpClientInterface::class), $this->createStub(LoggerInterface::class));
        $delay = (new \ReflectionMethod($executor, 'retryDelayMsFromHeadersOrBackoff'))->invoke($executor, $response, 100);

        $this->assertSame($expectedMs, $delay);
    }

    public function testDownloadAboveTheSizeLimitIsAborted(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(str_repeat('a', RequestExecutor::MAX_RESPONSE_BYTES + 1)),
        ]);

        $executor = new RequestExecutor([], $httpClient, $this->createStub(LoggerInterface::class));
        $result = $executor->requestChunk(['https://example.com/huge'], $this->makeConfig(['sp_max_retry' => 1]));

        // The abort is a transport error: the URL is left out, nothing is kept in memory.
        $this->assertSame([], $result);
    }

    public function testRequestChunkDeduplicatesUrls(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->makeHttpClient($response);
        $config = $this->makeConfig(['sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([], $httpClient, $logger);
        $result = $executor->requestChunk([
            'https://example.com/a',
            'https://example.com/a',
        ], $config);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('https://example.com/a', $result);
    }

    public function testRequestChunkRetriesRetryableStatusInWaves(): void
    {
        $failResponse = $this->createStub(ResponseInterface::class);
        $failResponse->method('getStatusCode')->willReturn(500);
        $failResponse->method('getHeaders')->willReturn([]);

        $successResponse = $this->createStub(ResponseInterface::class);
        $successResponse->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturnOnConsecutiveCalls($failResponse, $successResponse);

        $config = $this->makeConfig(['sp_max_retry' => 3, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([500], $httpClient, $logger);
        $result = $executor->requestChunk(['https://example.com/'], $config);

        $this->assertSame($successResponse, $result['https://example.com/']);
    }

    public function testRequestChunkKeepsLastResponseWhenRetriesExhausted(): void
    {
        $failResponse = $this->createStub(ResponseInterface::class);
        $failResponse->method('getStatusCode')->willReturn(500);
        $failResponse->method('getHeaders')->willReturn([]);

        $httpClient = $this->makeHttpClient($failResponse);
        $config = $this->makeConfig(['sp_max_retry' => 2, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([500], $httpClient, $logger);
        $result = $executor->requestChunk(['https://example.com/'], $config);

        // Non-2xx response is kept so the caller can decide how to handle it.
        $this->assertSame($failResponse, $result['https://example.com/']);
    }

    public function testRequestChunkOmitsUrlAfterTransportExhaustion(): void
    {
        $transportException = new class ('timeout') extends \Exception implements TransportExceptionInterface {};

        // Symfony's HttpClient is lazy: transport errors surface when the
        // response is read, not when request() is called.
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willThrowException($transportException);

        $httpClient = $this->makeHttpClient($response);

        $config = $this->makeConfig(['sp_max_retry' => 2, 'sp_backoff_ms' => 0]);
        $logger = $this->createStub(LoggerInterface::class);

        $executor = new RequestExecutor([], $httpClient, $logger);
        $result = $executor->requestChunk(['https://example.com/'], $config);

        $this->assertSame([], $result);
    }

    /**
     * The executor is shared across sites, so the per-site user agent has to
     * travel with every request instead of being fixed on the client.
     */
    public function testSendsUserAgentOfTheConfigPassedPerCall(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        /** @var list<mixed> $sentUserAgents */
        $sentUserAgents = [];
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(
            static function (string $method, string $url, array $options) use (&$sentUserAgents, $response): ResponseInterface {
                $sentUserAgents[] = $options['headers']['User-Agent'] ?? null;

                return $response;
            },
        );

        $executor = new RequestExecutor([], $httpClient, $this->createStub(LoggerInterface::class));
        $executor->request('https://example.com/', $this->makeConfig(['sp_user_agent' => 'SiteA/1.0']));
        $executor->requestChunk(['https://example.com/b'], $this->makeConfig(['sp_user_agent' => 'SiteB/1.0']));

        $this->assertSame(['SiteA/1.0', 'SiteB/1.0'], $sentUserAgents);
    }

    public function testResetForgetsThrottleTimestamps(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);

        $config = $this->makeConfig(['sp_delay_ms' => 200]);
        $executor = new RequestExecutor([], $httpClient, $this->createStub(LoggerInterface::class));

        $executor->throttle('https://example.com/page', $config);
        $executor->reset();

        $start = microtime(true);
        $executor->throttle('https://example.com/page', $config); // no previous request known → no sleep
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.1, $elapsed);
    }

    public function testThrottleSleedsWhenSecondCallIsTooFastForSameHost(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);

        // 50ms delay: second call within 50ms of first → usleep is triggered
        $config = $this->makeConfig(['sp_delay_ms' => 50]);
        $logger = $this->createStub(LoggerInterface::class);
        $executor = new RequestExecutor([], $httpClient, $logger);

        $start = microtime(true);
        $executor->throttle('https://example.com/page', $config);
        $executor->throttle('https://example.com/page', $config); // same URL / same host → triggers sleep
        $elapsed = microtime(true) - $start;

        // At least some throttle delay was applied (50ms = 0.05s)
        $this->assertGreaterThanOrEqual(0.04, $elapsed);
    }
}
