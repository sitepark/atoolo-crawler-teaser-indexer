<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Collector;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;

/**
 * Brings discovered URLs into one canonical form, so that the same page is
 * recognised as the same URL - for deduplication, for "fetch every page only
 * once" and for the prefix rules of the {@see LinkFilterInterface}, which
 * always run on canonical URLs.
 *
 * Canonical form: lowercase scheme and host, no default port (80/443),
 * configured query parameters removed (`sp_strip_query_params`), fragments
 * removed for the configured prefixes (`sp_strip_fragments`). The result is
 * deduplicated with the original order preserved.
 *
 * It only rewrites, it never drops a URL - deciding which URLs to follow is
 * the LinkFilter's job. URLs that cannot be parsed are passed through
 * unchanged.
 */
final class UrlCanonicalizer
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * @param list<string> $urls
     *
     * @return list<string>
     */
    public function canonicalize(array $urls, PipelineConfig $config): array
    {
        $stripParams = $config->stripQueryParamsActive()
            ? array_flip($config->stripQueryParams())
            : [];
        $stripFragmentPrefixes = $config->stripFragments();

        $canonical = [];
        foreach ($urls as $url) {
            $url = $this->canonicalizeOne($url, $stripParams);
            $canonical[] = $this->startsWithAny($url, $stripFragmentPrefixes)
                ? $this->withoutFragment($url)
                : $url;
        }

        return array_values(array_unique($canonical));
    }

    /**
     * @param array<string, int> $stripParams query parameter names to remove (as keys)
     */
    private function canonicalizeOne(string $url, array $stripParams): string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $canonical = $scheme . '://' . strtolower($parts['host']);

        $port = $parts['port'] ?? null;
        if (null !== $port && $port !== (self::DEFAULT_PORTS[$scheme] ?? null)) {
            $canonical .= ':' . $port;
        }

        $canonical .= $parts['path'] ?? '';

        $queryParams = [];
        parse_str($parts['query'] ?? '', $queryParams);
        $queryParams = array_diff_key($queryParams, $stripParams);
        if ([] !== $queryParams) {
            $canonical .= '?' . http_build_query($queryParams);
        }

        if (isset($parts['fragment']) && '' !== $parts['fragment']) {
            $canonical .= '#' . $parts['fragment'];
        }

        return $canonical;
    }

    private function withoutFragment(string $url): string
    {
        $hashPos = strpos($url, '#');

        return false === $hashPos ? $url : substr($url, 0, $hashPos);
    }

    /**
     * @param list<string> $prefixes
     */
    private function startsWithAny(string $url, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ('' !== $prefix && str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
