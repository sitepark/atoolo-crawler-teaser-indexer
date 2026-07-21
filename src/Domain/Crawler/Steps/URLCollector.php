<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Domain\Crawler\Steps;

use Atoolo\CrawlerIndexer\Config\CrawlerConfig;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\RobotsTxtCheckerInterface;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\URLNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;

class URLCollector
{
    public function __construct(
        private readonly CrawlerConfig $config,
        private readonly URLNormalizer $urlNormalizer,
        private readonly LoggerInterface $logger,
        private RobotsTxtCheckerInterface $robotsTxtChecker,
        private readonly Fetcher $fetcher,
    ) {}

    /**
     * Breadth-first crawls all configured start URLs.
     *
     * URLs discovered beyond that depth are not fetched here; they are
     * returned as the generator's return value, for the main
     * Fetcher/Parser pipeline (orchestrated by the caller) to process.
     *
     * @return \Generator<int, array<int, array{url: string, html: string}>, mixed, list<string>>
     */
    public function collect(): \Generator
    {
        $maxDocuments = $this->config->maxTeaser();

        /** @var list<string> $collectedUrls */
        $collectedUrls = [];

        foreach ($this->config->startUrls() as $start) {
            $maxDepth = (int) $start['extraction_depth'];
            $visited = [];
            $queue = [['url' => $start['url'], 'depth' => 0]];

            for ($i = 0; $i < count($queue); ++$i) {
                $url = (string) $queue[$i]['url'];
                $depth = (int) $queue[$i]['depth'];

                if ($depth > $maxDepth || isset($visited[$url])) {
                    continue;
                }

                $visited[$url] = true;

                $fetched = $this->fetcher->fetchUrls([$url]);
                yield $fetched;

                $pageUrls = array_diff(
                    $this->findHrefUrlsByCssSelector($fetched, $url),
                    array_keys($visited),
                );

                foreach ($pageUrls as $pageUrl) {
                    $collectedUrls[] = $pageUrl;

                    if (count($collectedUrls) >= $maxDocuments) {
                        return $collectedUrls;
                    }

                    if ($depth < $maxDepth && !isset($visited[$pageUrl])) {
                        $queue[] = ['url' => $pageUrl, 'depth' => $depth + 1];
                    }
                }

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
        }

        return $collectedUrls;
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
