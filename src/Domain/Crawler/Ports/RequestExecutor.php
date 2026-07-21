<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Ports;

use Atoolo\Crawler\Config\CrawlerConfig;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RequestExecutor implements RequestExecutorInterface
{
    /** @var array<string, int> */
    private array $lastRequestPerHost = [];

    /**
     * @param array<int> $retryStatusCodes
     */
    public function __construct(
        private readonly array $retryStatusCodes,
        private readonly CrawlerConfig $config,
        private HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        $this->httpClient = $httpClient->withOptions([
            'headers' => ['User-Agent' => $this->config->userAgent()],
        ]);
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
    public function request(string $url): ?ResponseInterface
    {
        $attempts = 0;
        $backoffMs = $this->config->backoffMs();
        $response = null;

        while ($attempts < $this->config->maxRetry()) {
            try {
                $this->throttle($url);

                $response = $this->httpClient->request('GET', $url);
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
                    'maxRetry' => $this->config->maxRetry(),
                ]);

                if ($attempts < $this->config->maxRetry()) {
                    $waitMs = $this->retryDelayMsFromHeadersOrBackoff($response, $backoffMs);
                    usleep($waitMs * 1000);
                    $backoffMs *= 2;
                }
            } catch (TransportExceptionInterface $e) {
                ++$attempts;

                $this->logger->warning(
                    sprintf(
                        'Transport error on attempt %d/%d for %s: %s',
                        $attempts,
                        $this->config->maxRetry(),
                        $url,
                        $e->getMessage(),
                    ),
                    ['exception' => $e],
                );

                if ($attempts < $this->config->maxRetry()) {
                    if ($backoffMs <= 50) {
                        $waitMs = 200;
                    }

                    usleep($backoffMs * 1000);
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
    public function requestChunk(array $urls): array
    {
        /** @var array<string, ResponseInterface> $results */
        $results = [];

        $pending = array_values(array_unique($urls));
        /** @var array<string, int> $attempts */
        $attempts = array_fill_keys($pending, 0);
        $backoffMs = $this->config->backoffMs();

        while ([] !== $pending) {
            // Fire all requests of this wave; responses are lazy and start
            // transferring concurrently as soon as the first one is read.
            /** @var array<string, ResponseInterface> $responses */
            $responses = [];
            foreach ($pending as $url) {
                $responses[$url] = $this->httpClient->request('GET', $url);
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

                    if ($attempts[$url] >= $this->config->maxRetry()) {
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
                        'maxRetry' => $this->config->maxRetry(),
                    ]);

                    $retry[] = $url;
                    $waitMs = max($waitMs, $this->retryDelayMsFromHeadersOrBackoff($response, $backoffMs));
                } catch (TransportExceptionInterface $e) {
                    ++$attempts[$url];

                    if ($attempts[$url] >= $this->config->maxRetry()) {
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
                            $this->config->maxRetry(),
                            $url,
                            $e->getMessage(),
                        ),
                        ['exception' => $e],
                    );

                    $retry[] = $url;
                    $waitMs = max($waitMs, $backoffMs);
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
            return max(0, (int) $retryAfter * 1000);
        }

        return $fallbackDelayMs;
    }

    /**
     * Enforces a minimum delay between two requests to the same host.
     *
     * @param string $url The target URL (used to extract the host for throttling)
     */
    public function throttle(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return;
        }

        $nowUs = (int) (microtime(true) * 1_000_000);
        $delayUs = $this->config->delayMs() * 1000;

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
