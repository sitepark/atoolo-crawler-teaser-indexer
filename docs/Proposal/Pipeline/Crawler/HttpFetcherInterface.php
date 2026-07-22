<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Proposal\Pipeline\Crawler;

use Atoolo\CrawlerIndexer\Proposal\Config\HttpFetcherConfig;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface HttpFetcherInterface
{
    public function fetch(string $url, HttpFetcherConfig $config): ?ResponseInterface;
}
