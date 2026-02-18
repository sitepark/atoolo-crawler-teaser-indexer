<?php

/**
 * The Fetcher is responsible for downloading raw HTML content from a given list of URLs.
 * It processes the URLs in batches, executes HTTP requests, and collects the responses.
 * To improve reliability, it includes a retry mechanism with exponential backoff and
 * logs errors or failures for traceability.
 *
 * By encapsulating all network communication, the Fetcher acts as a dedicated
 * "retrieval" step within the pipeline, transforming input URLs into HTML data
 * that can be passed on to subsequent processing components.
 */

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Steps;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Atoolo\Crawler\Domain\Crawler\Ports\RequestExecutorInterface;

class Fetcher
{
    public function __construct(
        private readonly RequestExecutorInterface $requestExecutor,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Fetches raw HTML for multiple URLs in batches.
     *
     * @param string[] $urls A list of URLs
     * @return array<int, array{url: string, html: string}>
     */
    public function fetchUrls(array $urlChunk): array
    {
        $responses = $this->startRequests($urlChunk);
        return $this->processResponses($responses);
    }

    /**
     * Starts the HTTP requests for a batch of URLs with a retry mechanism.
     *
     * @param string[] $urls A list of URLs for the batch
     * @return ResponseInterface[] An array of ResponseInterface objects keyed by URL
     */
    private function startRequests(array $urlChunk): array
    {
        $responses = [];
        foreach ($urlChunk as $url) {
            $responses[$url] = $this->requestExecutor->request($url);
        }
        return $responses;
    }

    /**
     * Processes the collected responses and extracts the HTML content.
     *
     * @param ResponseInterface[] $responses The responses to process, keyed by URL
     * @return array<int, array{url: string, html: string}>
     */
    private function processResponses(array $responses): array
    {
        $results = [];
        foreach ($responses as $url => $response) {
            try {
                if (!$response) {
                    continue;
                }

                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $results[] = [
                        'url'  => $url,
                        'html' => $response->getContent(),
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Failed to retrieve content",
                    [
                        'baseUrl'   => $url,
                        'exception' => $e
                    ]
                );
            }
        }
        return $results;
    }
}
