<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Steps;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Link;
use Atoolo\Crawler\Domain\Crawler\Services\URLNormalizer;
use Atoolo\Crawler\Domain\Crawler\Ports\RequestExecutorInterface;
use Atoolo\Crawler\Domain\Crawler\Services\RobotsTxtCheckerInterface;
use Atoolo\Crawler\Config\CrawlerConfig;

class URLCollector
{
    public function __construct(
        private readonly CrawlerConfig $config,
        private readonly URLNormalizer $urlNormalizer,
        private readonly LoggerInterface $logger,
        private readonly RequestExecutorInterface $requestExecutor,
        private RobotsTxtCheckerInterface $robotsTxtChecker
    ) {
    }

    /**
     * Collects and filters all discoverable href URLs from the configured start URLs.
     *
     * The method loads each start page, limits link extraction to the configured
     * DOM section, resolves absolute URLs, removes duplicates and finally applies
     * allow/deny path filtering.
     *
     * @return array<int, string> A list of unique, filtered absolute URLs
     */
    public function findHrefUrlsByCssSelector(): array
    {
        $urls = [];

        foreach ($this->config->startUrls() as $start) {
            $urls = array_merge($urls, $this->crawlByDepth($start));
        }

        $urls = $this->urlNormalizer->normalize($urls);

        if ($this->config->respectRobotsTxt()) {
            $urls = $this->robotsTxtChecker->filterAllowed($urls);
        }

        if (count($urls) > $this->config->maxTeaser()) {
            $urls = array_values(array_slice($urls, 0, $this->config->maxTeaser()));
        }

        if ($this->config->forcedArticleUrls() !== null) {
            return array_values(array_unique(array_merge($urls, $this->config->forcedArticleUrls())));
        }

        return array_values(array_unique($urls));
    }

    /**
     * Loads and returns a DOM crawler instance for the given base URL.
     *
     * @param string $baseUrl The URL to fetch and parse
     * @return Crawler The initialized HTML crawler
     *
     * @throws \LogicException If the crawler could not be initialized
     */
    private function loadCrawlerForBaseUrl(string $baseUrl): Crawler
    {
        $response = $this->requestExecutor->request($baseUrl);
        if ($response === null) {
            throw new \LogicException('Request failed.');
        }

        $htmlContent = $response->getContent(false);
        return new Crawler($htmlContent, $baseUrl);
    }

    /**
     * Resolves the DOM scope in which link discovery is allowed.
     *
     * If a link section selector is configured, only this section is used.
     * If the section cannot be found, null is returned and the URL is skipped.
     *
     * @param Crawler $crawler The full-page HTML crawler
     * @param string  $baseUrl The base URL used for logging context
     * @return Crawler|null The scoped crawler or null if the section was not found
     */
    private function resolveScope(Crawler $crawler, string $baseUrl): ?Crawler
    {
        $linkSection = $this->config->linkSection();
        if ($linkSection === '') {
            return $crawler;
        }
        $scope = $crawler->filter($linkSection);

        if ($scope->count() === 0) {
            $this->logger->warning('Link section in html not found', [
                'section' => $linkSection,
                'url'     => $baseUrl,
            ]);
            return null;
        }
        return $scope;
    }

    /**
     * Crawls URLs breadth-first from a start URL up to its extraction_depth.
     *
     * @param array{url:string, extraction_depth:int} $start
     * @return array<int, string>
     */
    /**
     * @param array{url:string, extraction_depth:int} $start
     * @return array<int, string>
     */
    private function crawlByDepth(array $start): array
    {
        $found = [];
        $maxDepth = (int) $start['extraction_depth'];
        $limit = $this->config->maxTeaser(); // <- früh limitieren

        $queue   = [['url' => $start['url'], 'depth' => 0]];
        $visited = [];

        $denyPrefixes  = $this->config->denyPrefixes();
        $allowPrefixes = $this->config->allowPrefixes();

        for ($i = 0; $i < count($queue); $i++) {
            if (count($found) >= $limit) {
                break;
            }

            $url   = $queue[$i]['url'];
            $depth = (int) $queue[$i]['depth'];

            if ($depth > $maxDepth || isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            $crawler = $this->loadCrawlerForBaseUrl($url);
            $scope   = $this->resolveScope($crawler, $url);
            if ($scope === null) {
                continue;
            }

            foreach ($this->extractAbsoluteUrlsFromScope($scope, $url) as $link) {
                if ($this->startsWithAny($link, $denyPrefixes)) {
                    continue;
                }
                if ($allowPrefixes !== [] && !$this->startsWithAny($link, $allowPrefixes)) {
                    continue;
                }

                $found[] = $link;
                if (count($found) >= $limit) {
                    break 2; // raus aus foreach + for
                }

                if ($depth < $maxDepth && !isset($visited[$link])) {
                    $queue[] = ['url' => $link, 'depth' => $depth + 1];
                }
            }

            // wichtige Speicherhilfe
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return $found;
    }

    /**
     * @param list<string> $prefixes
     */
    private function startsWithAny(string $url, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($url, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extracts absolute HTTP(S) URLs from the given DOM scope.
     *
     * Relative URLs are resolved against the provided base URL.
     * Invalid or non-HTTP(S) links are ignored.
     *
     * @param Crawler $scope   The scoped DOM crawler
     * @param string  $baseUrl The base URL used for resolving relative links
     * @return array<int, string> A list of extracted absolute URLs
     */
    private function extractAbsoluteUrlsFromScope(Crawler $scope, string $baseUrl): array
    {
        $found = $scope
            ->filter($this->config->linkSelector())
            ->each(function (Crawler $node) use ($baseUrl): ?string {
                $domElement = $node->getNode(0);

                if (!$domElement instanceof \DOMElement) {
                    return null;
                }

                try {
                    $link = new Link($domElement, $baseUrl);
                    $url  = $link->getUri();
                    return str_starts_with($url, 'https://') ? $url : null;
                } catch (\Throwable $e) {
                    $this->logger->debug('Failed to parse link', [
                        'baseUrl'   => $baseUrl,
                        'exception' => $e,
                    ]);
                    return null;
                }
            });

        /** @var array<int, string> $urls */
        return array_values(array_filter($found));
    }
}
