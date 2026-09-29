<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Ports;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

final class RequestExecutor implements RequestExecutorInterface, ResetInterface
{
    /** A longer Retry-After would block the worker (the whole wave waits). */
    private const MAX_RETRY_AFTER_S = 120;

    /** Shortest wait before a retry after a transport error. */
    private const MIN_RETRY_DELAY_MS = 200;

    /**
     * Downloads above this size are aborted, so a huge file cannot exhaust
     * the memory. Same limit as the Parser's, bigger pages are skipped anyway.
     */
    public const MAX_RESPONSE_BYTES = 2_000_000;

    /** @var array<string, int> */
    private array $lastRequestPerHost = [];

    /**
     * @param array<int> $retryStatusCodes
     */
    public function __construct(
        private readonly array $retryStatusCodes,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Forgets the per-host throttle timestamps, so a new crawl run does not
     * inherit the timing of the previous one.
     */
    public function reset(): void
    {
        $this->lastRequestPerHost = [];
    }

    /**
     * Executes an HTTP request with per-host throttling and retry logic.
     *
     * Retries:
     * - Transport errors (timeouts, DNS, connection issues)
     * - HTTP 429 (rate limit)
     * - HTTP 500-504 (typical transient server/proxy errors)
     *
     * For HTTP 429 (and sometimes 503), respects Retry-After (seconds) when present.
     *
     * @param string $url The URL to request
     *
     * @return ResponseInterface|null The response or null if all retries failed due to transport errors
     */
    public function request(string $url, PipelineConfig $config): ?ResponseInterface
    {
        $attempts = 0;
        $backoffMs = $config->backoffMs();
        $response = null;

        while ($attempts < $config->maxRetry()) {
            try {
                $this->throttle($url, $config);

                $response = $this->httpClient->request('GET', $url, $this->requestOptions($config));
                $status = $response->getStatusCode();

                $isSuccess = ($status >= 200 && $status < 300);
                $isRetryable = in_array($status, $this->retryStatusCodes, true);

                if ($isSuccess || !$isRetryable) {
                    break;
                }

                ++$attempts;

                $this->logger->warning('Retryable HTTP status received', [
                    'url' => $url,
                    'status' => $status,
                    'attempt' => $attempts,
                    'maxRetry' => $config->maxRetry(),
                ]);

                if ($attempts < $config->maxRetry()) {
                    $waitMs = $this->retryDelayMsFromHeadersOrBackoff($response, $backoffMs);
                    usleep($waitMs * 1000);
                    $backoffMs *= 2;
                }
            } catch (TransportExceptionInterface $e) {
                $response = null;
                ++$attempts;

                $this->logger->warning(
                    sprintf(
                        'Transport error on attempt %d/%d for %s: %s',
                        $attempts,
                        $config->maxRetry(),
                        $url,
                        $e->getMessage(),
                    ),
                    ['exception' => $e],
                );

                if ($attempts < $config->maxRetry()) {
                    usleep(max($backoffMs, self::MIN_RETRY_DELAY_MS) * 1000);
                    $backoffMs *= 2;
                }
            }
        }

        if (null === $response) {
            $this->logger->error('Request failed after all retries', ['url' => $url]);
        }

        return $response;
    }

    /**
     * Executes all requests of a chunk concurrently.
     *
     * Unlike {@see request()}, this method first fires every request of the
     * chunk (Symfony's HttpClient returns lazy responses immediately) and only
     * then reads them. Reading the first response pumps the transfer of all
     * in-flight requests, so the whole chunk is downloaded concurrently instead
     * of one URL after another. The chunk size therefore acts as the effective
     * concurrency limit.
     *
     * Retries are handled wave by wave: URLs that hit a transport error or a
     * configured retry status code are collected and fired together again,
     * with a single backoff sleep between waves, until they succeed or exhaust
     * `sp_max_retry`.
     *
     * @param list<string> $urls
     *
     * @return array<string, ResponseInterface> Responses keyed by URL
     */
    public function requestChunk(array $urls, PipelineConfig $config): array
    {
        /** @var array<string, ResponseInterface> $results */
        $results = [];

        $pending = array_values(array_unique($urls));
        /** @var array<string, int> $attempts */
        $attempts = array_fill_keys($pending, 0);
        $backoffMs = $config->backoffMs();

        while ([] !== $pending) {
            // Fire all requests of this wave; responses are lazy and start
            // transferring concurrently as soon as the first one is read.
            /** @var array<string, ResponseInterface> $responses */
            $responses = [];
            foreach ($pending as $url) {
                try {
                    $responses[$url] = $this->httpClient->request('GET', $url, $this->requestOptions($config));
                } catch (\Throwable $e) {
                    // e.g. a malformed URL: skip it, the rest of the chunk goes on
                    $this->logger->error('Request could not be sent', ['url' => $url, 'exception' => $e]);
                }
            }

            /** @var list<string> $retry */
            $retry = [];
            $waitMs = 0;

            foreach ($responses as $url => $response) {
                try {
                    $status = $response->getStatusCode();

                    $isSuccess = ($status >= 200 && $status < 300);
                    $isRetryable = in_array($status, $this->retryStatusCodes, true);

                    if ($isSuccess || !$isRetryable) {
                        $results[$url] = $response;

                        continue;
                    }

                    ++$attempts[$url];

                    if ($attempts[$url] >= $config->maxRetry()) {
                        // Retries exhausted: keep the last (non-2xx) response so
                        // the caller can decide how to handle it.
                        $results[$url] = $response;
                        $this->logger->error('Request failed after all retries', [
                            'url' => $url,
                            'status' => $status,
                        ]);

                        continue;
                    }

                    $this->logger->warning('Retryable HTTP status received', [
                        'url' => $url,
                        'status' => $status,
                        'attempt' => $attempts[$url],
                        'maxRetry' => $config->maxRetry(),
                    ]);

                    $retry[] = $url;
                    $waitMs = max($waitMs, $this->retryDelayMsFromHeadersOrBackoff($response, $backoffMs));
                } catch (TransportExceptionInterface $e) {
                    ++$attempts[$url];

                    if ($attempts[$url] >= $config->maxRetry()) {
                        $this->logger->error('Request failed after all retries', [
                            'url' => $url,
                            'exception' => $e,
                        ]);

                        continue;
                    }

                    $this->logger->warning(
                        sprintf(
                            'Transport error on attempt %d/%d for %s: %s',
                            $attempts[$url],
                            $config->maxRetry(),
                            $url,
                            $e->getMessage(),
                        ),
                        ['exception' => $e],
                    );

                    $retry[] = $url;
                    $waitMs = max($waitMs, $backoffMs, self::MIN_RETRY_DELAY_MS);
                }
            }

            $pending = $retry;

            if ([] !== $pending) {
                usleep($waitMs * 1000);
                $backoffMs *= 2;
            }
        }

        return $results;
    }

    /**
     * The User-Agent is sent per request, because the executor is shared across
     * sites while the user agent is a per-site setting.
     *
     * An exception thrown in on_progress aborts the download; the caller
     * sees it as a transport error when reading the response.
     *
     * @return array{headers: array{User-Agent: string}, on_progress: \Closure(int, int): void}
     */
    private function requestOptions(PipelineConfig $config): array
    {
        return [
            'headers' => ['User-Agent' => $config->userAgent()],
            'on_progress' => static function (int $downloaded, int $size): void {
                if ($downloaded > self::MAX_RESPONSE_BYTES || $size > self::MAX_RESPONSE_BYTES) {
                    throw new \RuntimeException(sprintf('Response larger than %d bytes, aborted', self::MAX_RESPONSE_BYTES));
                }
            },
        ];
    }

    /**
     * Determines the delay (in milliseconds) before retrying a request.
     *
     * @param ResponseInterface $response        The HTTP response (used to read headers)
     * @param int               $fallbackDelayMs The fallback backoff delay in milliseconds
     *
     * @return int The delay in milliseconds to wait before the next retry
     */
    private function retryDelayMsFromHeadersOrBackoff(ResponseInterface $response, int $fallbackDelayMs): int
    {
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;

        if (null !== $retryAfter && ctype_digit($retryAfter)) {
            return min((int) $retryAfter, self::MAX_RETRY_AFTER_S) * 1000;
        }

        return $fallbackDelayMs;
    }

    /**
     * Enforces a minimum delay between two requests to the same host.
     *
     * @param string $url The target URL (used to extract the host for throttling)
     */
    public function throttle(string $url, PipelineConfig $config): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return;
        }

        $nowUs = (int) (microtime(true) * 1_000_000);
        $delayUs = $config->delayMs() * 1000;

        if (isset($this->lastRequestPerHost[$host])) {
            $elapsedUs = $nowUs - $this->lastRequestPerHost[$host];
            if ($elapsedUs < $delayUs) {
                usleep($delayUs - $elapsedUs);
                $nowUs = (int) (microtime(true) * 1_000_000);
            }
        }

        $this->lastRequestPerHost[$host] = $nowUs;
    }
}
