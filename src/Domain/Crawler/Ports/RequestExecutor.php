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

    public function __construct(
        private readonly CrawlerConfig $config,
        private HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
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
     * @return ResponseInterface|null The response or null if all retries failed due to transport errors
     */
    public function request(string $url): ?ResponseInterface
    {
        $attempts = 0;
        $backoffMs = $this->config->delayMs();
        $response = null;

        while ($attempts < $this->config->maxRetry()) {
            try {
                $this->throttle($url);

                $response = $this->httpClient->request('GET', $url);
                $status = $response->getStatusCode();

                $isSuccess = ($status >= 200 && $status < 300);
                $isRetryable = in_array($status, $this->config->retryStatusCodes(), true);

                // success OR non-retryable -> stop
                if ($isSuccess || !$isRetryable) {
                    break;
                }

                $attempts++;

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
                $attempts++;

                $this->logger->warning(
                    sprintf(
                        'Transport error on attempt %d/%d for %s: %s',
                        $attempts,
                        $this->config->maxRetry(),
                        $url,
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );

                if ($attempts < $this->config->maxRetry()) {
                    usleep($backoffMs * 1000);
                    $backoffMs *= 2;
                }
            }
        }

        if ($response === null) {
            $this->logger->error('Request failed after all retries', ['url' => $url]);
        }

        return $response;
    }


    /**
     * Determines the delay (in milliseconds) before retrying a request.
     *
     * @param ResponseInterface $response The HTTP response (used to read headers)
     * @param int $fallbackBackoffMs The fallback backoff delay in milliseconds
     * @return int The delay in milliseconds to wait before the next retry
     */
    private function retryDelayMsFromHeadersOrBackoff(ResponseInterface $response, int $fallbackBackoffMs): int
    {
        $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;

        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            return max(0, (int) $retryAfter * 1000);
        }

        return $fallbackBackoffMs;
    }

    /**
     * Enforces a minimum delay between two requests to the same host.
     *
     * @param string $url The target URL (used to extract the host for throttling)
     * @return void
     */
    public function throttle(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return;
        }

        $nowUs = (int) (microtime(true) * 1_000_000);
        $delayUs = $this->config->delayMS() * 1000;

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
