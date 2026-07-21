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

namespace Atoolo\CrawlerIndexer\Domain\Crawler\Steps;

use Atoolo\CrawlerIndexer\Domain\Crawler\Ports\RequestExecutorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class Fetcher
{
    public function __construct(
        private readonly RequestExecutorInterface $requestExecutor,
        private LoggerInterface $logger,
    ) {}

    /**
     * Fetches raw HTML for multiple URLs concurrently.
     *
     * The whole chunk is requested at once so the HTTP transfers run in
     * parallel; the chunk size (`sp_parallel_requests`) therefore bounds the
     * concurrency.
     *
     * @param list<string> $urlChunk
     *
     * @return array<int, array{url: string, html: string}>
     */
    public function fetchUrls(array $urlChunk): array
    {
        $responses = $this->requestExecutor->requestChunk($urlChunk);

        return $this->processResponses($responses);
    }

    /**
     * Processes the collected responses and extracts the HTML content.
     *
     * @param ResponseInterface[] $responses The responses to process, keyed by URL
     *
     * @return array<int, array{url: string, html: string}>
     */
    private function processResponses(array $responses): array
    {
        $results = [];
        foreach ($responses as $url => $response) {
            try {
                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $results[] = [
                        'url' => $url,
                        'html' => $response->getContent(),
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to retrieve content',
                    [
                        'baseUrl' => $url,
                        'exception' => $e,
                    ],
                );
            }
        }

        return $results;
    }
}
