<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Proposal\Pipeline\Crawler;

use Atoolo\CrawlerIndexer\Proposal\Config\HttpFetcherConfig;
use Atoolo\CrawlerIndexer\Proposal\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Proposal\Dto\CrawledPage;
use Atoolo\CrawlerIndexer\Proposal\Pipeline\CrawlerStepInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;

/** Discovers URLs via BFS and streams each page as a CrawledPage (merged URLCollector + Fetcher). */
final class CrawlerStep implements CrawlerStepInterface
{
    public function __construct(
        private readonly HttpFetcherInterface $httpFetcher,
        private readonly RobotsTxtCheckerInterface $robotsTxtChecker,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return iterable<CrawledPage>
     */
    public function crawl(PipelineConfig $config): iterable
    {
        /** @var array<string, true> $yieldedUrls */
        $yieldedUrls = [];
        yield from $this->streamForcedArticles($config, $yieldedUrls);
        yield from $this->streamFromBfs($config, $yieldedUrls);
    }

    /**
     * @param array<string, true> $yieldedUrls
     *
     * @return \Generator<int, CrawledPage>
     */
    private function streamFromBfs(PipelineConfig $config, array &$yieldedUrls): \Generator
    {
        /** @var array<string, true> $pendingArticles */
        $pendingArticles = [];
        $yieldCount      = 0;

        foreach ($config->startUrls as $start) {
            if ($yieldCount >= $config->maxItems) {
                return;
            }
            yield from $this->streamByDepth($start, $config, $pendingArticles, $yieldedUrls, $yieldCount);
        }

        // Yield article URLs that were never BFS-visited (extraction_depth = 0).
        foreach (array_keys($pendingArticles) as $url) {
            if ($yieldCount >= $config->maxItems) {
                return;
            }
            $html = $this->fetchHtml($url, $config->httpFetcherConfig);
            if (null !== $html) {
                $canonicalUrl = $this->resolveCanonicalUrl(new Crawler($html, $url), $url);
                $yieldedUrls[$url] = true;
                $yieldedUrls[$canonicalUrl] = true;
                ++$yieldCount;
                yield new CrawledPage($canonicalUrl, $html);
            }
        }
    }

    /**
     * forcedArticleUrls bypass maxItems; bereits ausgelieferte URLs werden übersprungen.
     *
     * @param array<string, true> $yieldedUrls
     *
     * @return \Generator<int, CrawledPage>
     */
    private function streamForcedArticles(PipelineConfig $config, array &$yieldedUrls): \Generator
    {
        foreach ($config->forcedArticleUrls as $url) {
            if (isset($yieldedUrls[$url])) {
                continue;
            }
            $html = $this->fetchHtml($url, $config->httpFetcherConfig);
            if (null !== $html) {
                $canonicalUrl = $this->resolveCanonicalUrl(new Crawler($html, $url), $url);
                $yieldedUrls[$url] = true;
                $yieldedUrls[$canonicalUrl] = true;
                yield new CrawledPage($canonicalUrl, $html);
            }
        }
    }

    /**
     * @param array{url: string, extraction_depth: int} $start
     * @param array<string, true>                       $pendingArticles
     * @param array<string, true>                       $yieldedUrls
     *
     * @return \Generator<int, CrawledPage>
     */
    private function streamByDepth(
        array $start,
        PipelineConfig $config,
        array &$pendingArticles,
        array &$yieldedUrls,
        int &$yieldCount,
    ): \Generator {
        $maxDepth = (int) $start['extraction_depth'];
        $startUrl = $this->normalizeOne($start['url'], $config);

        // start URL is itself an article candidate
        $pendingArticles[$startUrl] = true;

        $queue   = [['url' => $startUrl, 'depth' => 0]];
        $visited = [];

        for ($i = 0; $i < count($queue); ++$i) {
            if ($yieldCount >= $config->maxItems) {
                break;
            }

            $url   = $queue[$i]['url'];
            $depth = (int) $queue[$i]['depth'];

            if ($depth > $maxDepth || isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            $html = $this->fetchHtml($url, $config->httpFetcherConfig);
            if (null === $html) {
                continue;
            }

            $crawler      = new Crawler($html, $url);
            $canonicalUrl = $this->resolveCanonicalUrl($crawler, $url);

            // yield if this page is an article candidate
            if (isset($pendingArticles[$url]) && $yieldCount < $config->maxItems) {
                unset($pendingArticles[$url]);
                $yieldedUrls[$url] = true;
                $yieldedUrls[$canonicalUrl] = true;
                ++$yieldCount;
                yield new CrawledPage($canonicalUrl, $html);
            }

            foreach ($this->extractLinks($crawler, $url, $config) as $link) {
                if (!isset($visited[$link]) && !isset($pendingArticles[$link]) && !isset($yieldedUrls[$link])) {
                    if (!$config->respectRobotsTxt || $this->robotsTxtChecker->isAllowed($link)) {
                        $pendingArticles[$link] = true;
                    }
                }
                if ($depth < $maxDepth && !isset($visited[$link])) {
                    $queue[] = ['url' => $link, 'depth' => $depth + 1];
                }
            }

            gc_collect_cycles();
        }
    }

    /**
     * Fetches a URL and returns its HTML body, or null on failure.
     */
    private function fetchHtml(string $url, HttpFetcherConfig $fetcherConfig): ?string
    {
        $response = $this->httpFetcher->fetch($url, $fetcherConfig);
        if (null === $response) {
            return null;
        }

        try {
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('[Crawler] Non-2xx response', ['url' => $url, 'status' => $status]);

                return null;
            }

            return $response->getContent();
        } catch (\Throwable $e) {
            $this->logger->error('[Crawler] Failed to retrieve content', ['url' => $url, 'exception' => $e]);

            return null;
        }
    }

    /**
     * Returns the canonical URL declared in <link rel="canonical">, or $fetchedUrl if absent.
     * Marks both URLs as yielded so neither is fetched again during BFS.
     */
    private function resolveCanonicalUrl(Crawler $crawler, string $fetchedUrl): string
    {
        $node = $crawler->filter('link[rel="canonical"]');
        if (0 === $node->count()) {
            return $fetchedUrl;
        }
        $href = $node->first()->attr('href');

        return (null !== $href && '' !== $href) ? $href : $fetchedUrl;
    }

    /**
     * Extracts, normalises and filters absolute HTTPS links from a page.
     *
     * @return list<string>
     */
    private function extractLinks(Crawler $scope, string $baseUrl, PipelineConfig $config): array
    {
        $links = $scope
            ->filter($config->linkSelector)
            ->each(function (Crawler $node) use ($baseUrl, $config): ?string {
                $el = $node->getNode(0);
                if (!$el instanceof \DOMElement) {
                    return null;
                }

                try {
                    $url = (new Link($el, $baseUrl))->getUri();
                } catch (\Throwable $e) {
                    $this->logger->debug('[Crawler] Failed to parse link', ['baseUrl' => $baseUrl, 'exception' => $e]);

                    return null;
                }

                if (!str_starts_with($url, 'https://')) {
                    return null;
                }

                $url = $this->normalizeOne($url, $config);

                if ($this->startsWithAny($url, $config->denyPrefixes)) {
                    return null;
                }
                if ([] !== $config->allowPrefixes && !$this->startsWithAny($url, $config->allowPrefixes)) {
                    return null;
                }
                if ($this->hasDeniedEnding($url, $config->denyEndings)) {
                    return null;
                }

                return $url;
            });

        return array_values(array_filter($links));
    }

    private function normalizeOne(string $url, PipelineConfig $config): string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $normalized = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port']) && is_scalar($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }
        $normalized .= is_string($parts['path'] ?? null) ? $parts['path'] : '';

        $query = $parts['query'] ?? '';
        parse_str(is_string($query) ? $query : '', $queryParams);

        if ([] !== $config->stripQueryParams) {
            foreach ($config->stripQueryParams as $param) {
                unset($queryParams[$param]);
            }
        }

        if ([] !== $queryParams) {
            $normalized .= '?' . http_build_query($queryParams);
        }

        return $normalized;
    }

    /** @param list<string> $prefixes */
    private function startsWithAny(string $url, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ('' !== $prefix && str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $endings */
    private function hasDeniedEnding(string $url, array $endings): bool
    {
        if ([] === $endings) {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            return false;
        }
        $lowerPath = strtolower($path);
        foreach ($endings as $ending) {
            if ('' !== $ending && str_ends_with($lowerPath, strtolower($ending))) {
                return true;
            }
        }

        return false;
    }
}
