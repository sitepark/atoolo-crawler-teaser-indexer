<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Collector;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Pipeline\Fetcher\Fetcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;

class URLCollector
{
    public function __construct(
        private readonly PipelineConfig $config,
        private readonly URLNormalizer $urlNormalizer,
        private readonly LoggerInterface $logger,
        private RobotsTxtCheckerInterface $robotsTxtChecker,
        private readonly Fetcher $fetcher,
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
    public function collect(): \Generator
    {
        /** @var array<string, true> $visited */
        $visited = [];
        $documentCount = 0;
        $maxDocuments = $this->config->maxTeaser();

        foreach ($this->fetchInChunks($this->config->forcedArticleUrls()) as $chunk) {
            yield $chunk;
        }

        foreach ($this->config->startUrls() as $start) {
            $maxDepth = (int) $start['extraction_depth'];
            /** @var list<string> $currentLevel */
            $currentLevel = [(string) $start['url']];
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

                foreach (array_chunk($level, max(1, $this->config->parallelRequests())) as $chunk) {
                    if ($documentCount >= $maxDocuments) {
                        return;
                    }

                    $fetched = $this->fetcher->fetchUrls($chunk);
                    if ([] === $fetched) {
                        continue;
                    }

                    yield $fetched;
                    $documentCount += count($fetched);

                    if ($discover) {
                        $nextLevel = [...$nextLevel, ...$this->discoverLinks($fetched, $visited)];
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
    private function fetchInChunks(array $urls): array
    {
        $chunks = [];
        foreach (array_chunk($urls, max(1, $this->config->parallelRequests())) as $chunk) {
            $fetched = $this->fetcher->fetchUrls($chunk);
            if ([] !== $fetched) {
                $chunks[] = $fetched;
            }
        }

        return $chunks;
    }

    /**
     * Extracts, filters and deduplicates the links found on the given
     * fetched pages, skipping anything already visited.
     *
     * @param array<int, array{url: string, html: string}> $fetchedPages
     * @param array<string, true>                          $visited
     *
     * @return list<string>
     */
    private function discoverLinks(array $fetchedPages, array $visited): array
    {
        $links = [];
        foreach ($fetchedPages as $page) {
            foreach ($this->findHrefUrlsByCssSelector([$page], $page['url']) as $link) {
                if (!isset($visited[$link])) {
                    $links[] = $link;
                }
            }
        }

        return $links;
    }

    /**
     * Collects and filters all discoverable href URLs from the given fetched pages.
     *
     * Resolves absolute URLs, removes duplicates and applies allow/deny
     * path filtering (via URLNormalizer) as well as robots.txt filtering.
     *
     * @param array<int, array{url: string, html: string}> $htmlData
     *
     * @return list<string> A list of unique, filtered absolute URLs
     */
    private function findHrefUrlsByCssSelector(array $htmlData, string $baseUrl): array
    {
        $urls = [];
        foreach ($htmlData as $html) {
            $crawler = new Crawler($html['html'], $baseUrl);
            $pageUrls = $this->extractAbsoluteUrlsFromScope($crawler, $baseUrl);

            array_push($urls, ...$this->urlNormalizer->normalize($pageUrls));

            if ($this->config->respectRobotsTxt()) {
                $urls = $this->robotsTxtChecker->filterAllowed(array_values($urls));
            }
        }

        return array_values(array_unique(array_filter($urls, 'is_string')));
    }

    /**
     * Extracts absolute HTTPS URLs from the given DOM scope.
     *
     * Relative URLs are resolved against the provided base URL.
     * Invalid or non-HTTPS links are ignored.
     *
     * @param Crawler $crawler The scoped DOM crawler
     * @param string  $baseUrl The base URL used for resolving relative links
     *
     * @return array<int, string> A list of extracted absolute URLs
     */
    private function extractAbsoluteUrlsFromScope(Crawler $crawler, string $baseUrl): array
    {
        $found = $crawler
            ->filter($this->config->linkSelector())
            ->each(function (Crawler $node) use ($baseUrl): ?string {
                $domElement = $node->getNode(0);

                if (!$domElement instanceof \DOMElement) {
                    return null;
                }

                try {
                    $link = new Link($domElement, $baseUrl);
                    $url = $link->getUri();

                    return str_starts_with($url, 'https://') ? $url : null;
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
