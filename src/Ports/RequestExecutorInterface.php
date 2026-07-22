<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Ports;

use Symfony\Contracts\HttpClient\ResponseInterface;

interface RequestExecutorInterface
{
    public function request(string $url): ?ResponseInterface;

    /**
     * Executes all requests of a chunk concurrently and returns the final
     * responses keyed by URL.
     *
     * Retryable failures (transport errors and configured retry status codes)
     * are retried in waves: after a wave completes, the URLs that still need a
     * retry are fired together again, until they succeed or exhaust
     * `sp_max_retry`. URLs whose transport ultimately failed are omitted from
     * the result.
     *
     * @param list<string> $urls
     *
     * @return array<string, ResponseInterface> Responses keyed by URL
     */
    public function requestChunk(array $urls): array;
}
