<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Collector;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Pipeline\Fetcher\FetcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;

class URLCollector implements URLCollectorInterface
{
    public function __construct(
        private readonly UrlCanonicalizer $canonicalizer,
        private readonly LinkFilterInterface $linkFilter,
        private readonly LoggerInterface $logger,
        private readonly FetcherInterface $fetcher,
    ) {}

    /**
     * Breadth-first crawls all configured start URLs and streams every
     * fetched page.
     *
     * Each URL is fetched exactly once. A whole BFS level is fetched in
     * chunks of `sp_parallel_requests` (concurrent HTTP), each fetched
     * chunk is yielded straight downstream, and links are discovered from
     * the fetched HTML to build the next level. There is no second fetch
     * pass and no list of "collected URLs" - the caller only consumes the
     * yielded page chunks.
     *
     * `sp_forced_article_urls` are always fetched (independent of the
     * `sp_max_teaser` limit); the crawl itself stops once `sp_max_teaser`
     * pages have been streamed.
     *
     * @return \Generator<int, array<int, array{url: string, html: string}>>
     */
    public function collect(PipelineConfig $config): \Generator
    {
        /** @var array<string, true> $visited */
        $visited = [];
        $documentCount = 0;
        $maxDocuments = $config->maxTeaser();

        foreach ($this->fetchInChunks($config->forcedArticleUrls(), $config) as $chunk) {
            yield $chunk;
        }

        foreach ($config->startUrls() as $start) {
            $maxDepth = (int) $start['extraction_depth'];
            /** @var list<string> $currentLevel */
            $currentLevel = $this->canonicalizer->canonicalize([(string) $start['url']], $config);
            $depth = 0;

            while ([] !== $currentLevel) {
                $level = $this->unvisited($currentLevel, $visited);
                if ([] === $level) {
                    break;
                }

                // Mark the whole level visited up front, so links discovered
                // within it are never re-queued or fetched a second time.
                $this->markVisited($level, $visited);

                /** @var list<string> $nextLevel */
                $nextLevel = [];
                $discover = $depth <= $maxDepth;

                foreach (array_chunk($level, max(1, $config->parallelRequests())) as $chunk) {
                    if ($documentCount >= $maxDocuments) {
                        return;
                    }

                    $fetched = $this->fetcher->fetchUrls($chunk, $config);
                    if ([] === $fetched) {
                        continue;
                    }

                    yield $fetched;
                    $documentCount += count($fetched);

                    if ($discover) {
                        $nextLevel = [...$nextLevel, ...$this->discoverLinks($fetched, $visited, $config)];
                    }
                }

                // One level beyond maxDepth is still fetched (the leaf pages),
                // but we never discover further links from it.
                if (!$discover) {
                    break;
                }

                $currentLevel = array_values(array_unique($nextLevel));
                ++$depth;
            }
        }
    }

    /**
     * @param list<string>        $urls
     * @param array<string, true> $visited
     */
    private function markVisited(array $urls, array &$visited): void
    {
        foreach ($urls as $url) {
            $visited[$url] = true;
        }
    }

    /**
     * @param list<string>        $urls
     * @param array<string, true> $visited
     *
     * @return list<string>
     */
    private function unvisited(array $urls, array $visited): array
    {
        $out = [];
        foreach ($urls as $url) {
            if (!isset($visited[$url])) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Fetches the given URLs concurrently, chunk by chunk, and returns each
     * non-empty fetched chunk. Used for URLs that need no link discovery
     * (e.g. forced article URLs).
     *
     * @param list<string> $urls
     *
     * @return list<array<int, array{url: string, html: string}>>
     */
    private function fetchInChunks(array $urls, PipelineConfig $config): array
    {
        $chunks = [];
        foreach (array_chunk($urls, max(1, $config->parallelRequests())) as $chunk) {
            $fetched = $this->fetcher->fetchUrls($chunk, $config);
            if ([] !== $fetched) {
                $chunks[] = $fetched;
            }
        }

        return $chunks;
    }

    /**
     * Extracts the links found on the given fetched pages, canonicalizes and
     * filters them, and skips anything already visited.
     *
     * Canonicalizing comes first, so the filter rules and the visited check
     * always see the same form of a URL.
     *
     * @param array<int, array{url: string, html: string}> $fetchedPages
     * @param array<string, true>                          $visited
     *
     * @return list<string>
     */
    private function discoverLinks(array $fetchedPages, array $visited, PipelineConfig $config): array
    {
        $links = [];
        foreach ($fetchedPages as $page) {
            $crawler = new Crawler($page['html'], $page['url']);
            $pageLinks = $this->canonicalizer->canonicalize(
                $this->extractAbsoluteUrlsFromScope($crawler, $page['url'], $config),
                $config,
            );

            foreach ($this->linkFilter->filter($pageLinks, $config) as $link) {
                if (!isset($visited[$link])) {
                    $links[] = $link;
                }
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * Extracts absolute http(s) URLs from the given DOM scope.
     *
     * Relative URLs are resolved against the provided base URL. Links that fail
     * to parse are ignored, as are non-http(s) schemes: `mailto:`, `tel:` and
     * `javascript:` resolve to URIs without a host, which the normalizer passes
     * through unchanged - so they have to be dropped here.
     *
     * @param Crawler $crawler The scoped DOM crawler
     * @param string  $baseUrl The base URL used for resolving relative links
     *
     * @return list<string> A list of extracted absolute URLs
     */
    private function extractAbsoluteUrlsFromScope(Crawler $crawler, string $baseUrl, PipelineConfig $config): array
    {
        $found = $crawler
            ->filter($config->linkSelector())
            ->each(function (Crawler $node) use ($baseUrl): ?string {
                $domElement = $node->getNode(0);

                if (!$domElement instanceof \DOMElement) {
                    return null;
                }

                try {
                    $link = new Link($domElement, $baseUrl);
                    $url = $link->getUri();

                    return str_starts_with($url, 'https://') || str_starts_with($url, 'http://') ? $url : null;
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to parse link', [
                        'baseUrl' => $baseUrl,
                        'exception' => $e,
                    ]);

                    return null;
                }
            });

        return array_values(array_filter($found));
    }
}
