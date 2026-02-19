<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

use Atoolo\Crawler\Config\CrawlerConfig;

/**
 * Normalizes and filters URLs according to configurable rules.
 *
 * Responsibilities:
 * - Ensure URLs have a clean, canonical structure
 * - Optionally strip unwanted query parameters
 * - Apply allow/deny prefix filtering
 * - Remove duplicates while preserving order
 *
 * This class is intentionally deterministic:
 * given the same input and configuration, the output is always identical.
 */
final class URLNormalizer
{
    public function __construct(
        private readonly CrawlerConfig $config,
    ) {
    }

    /**
     * Applies the full URL normalization pipeline.
     *
     * Processing steps (in order):
     * 1. Sanitize URLs (parse + rebuild into canonical form)
     * 2. Remove configured query parameters (optional)
     * 3. Apply allow-prefix filtering
     * 4. Apply deny-prefix filtering
     * 5. Remove duplicates (order preserved)
     *
     * @param array<int,string> $rawUrls Raw, possibly unclean URLs
     *
     * @return array<int,string> Normalized and filtered URLs
     */
    public function normalize(array $rawUrls): array
    {
        $urls = $this->sanitizeUrls($rawUrls);
        $urls = $this->stripConfiguredQueryParams($urls);
        $urls = $this->filterAllowedUrlPath($urls);
        $urls = $this->filterUnneededUrls($urls);
        $urls = $this->filterDeniedEndings($urls);

        // Final deduplication while preserving original order
        return array_values(array_unique($urls));
    }

    /**
     * Sanitizes URLs by parsing and rebuilding them into a canonical structure.
     *
     * Invalid URLs (parse failure or missing scheme/host) are returned unchanged.
     *
     * @param array<int,string> $urls
     *
     * @return array<int,string> Sanitized URLs
     */
    private function sanitizeUrls(array $urls): array
    {
        $sanitized = array_map(function (string $url): string {
            $parts = parse_url($url);

            if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                return $url;
            }

            return $this->rebuildUrlFromParts(
                $parts,
                $this->parseQueryParams($parts)
            );
        }, $urls);

        return array_values($sanitized);
    }

    /**
     * Removes configured query parameters from URLs.
     *
     * If query stripping is disabled, URLs are returned unchanged.
     *
     * @param array<int,string> $urls
     *
     * @return array<int,string> URLs with unwanted query parameters removed
     */
    private function stripConfiguredQueryParams(array $urls): array
    {
        if ($this->config->stripQueryParamsActive() === false) {
            return $urls;
        }

        $paramNamesToRemove = array_flip($this->config->stripQueryParams());

        $stripped = array_map(function (string $url) use ($paramNamesToRemove): string {
            $parts = parse_url($url);

            if ($parts === false) {
                return $url;
            }

            $queryParams = $this->parseQueryParams($parts);

            foreach ($paramNamesToRemove as $name => $_) {
                unset($queryParams[$name]);
            }

            return $this->rebuildUrlFromParts($parts, $queryParams);
        }, $urls);

        return array_values($stripped);
    }

    /**
     * Extracts query parameters from parsed URL parts.
     *
     * @param array<string,mixed> $parts
     * @return array<int|string,mixed>
     */
    private function parseQueryParams(array $parts): array
    {
        $query = $parts['query'] ?? null;
        if (!is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $queryParams);

        return $queryParams;
    }

    /**
     * Rebuilds a URL string from parsed URL parts and query parameters.
     *
     * Ensures a consistent URL format:
     * scheme://host[:port]/path?query#fragment
     *
     * @param array<string,mixed> $parts
     * @param array<int|string,mixed> $queryParams
     */
    private function rebuildUrlFromParts(array $parts, array $queryParams): string
    {
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        if (!is_string($scheme) || !is_string($host)) {
            return '';
        }

        $url = $scheme . '://' . $host;

        if (isset($parts['port']) && is_scalar($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $path = $parts['path'] ?? '';
        if (is_string($path)) {
            $url .= $path;
        }

        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        $fragment = $parts['fragment'] ?? '';
        if (is_string($fragment) && $fragment !== '') {
            $url .= '#' . $fragment;
        }

        return $url;
    }

    /**
     * Filters URLs using an allow-list strategy.
     *
     * If allow prefixes are configured, only URLs starting with at least
     * one allowed prefix are kept.
     *
     * If no allow prefixes are defined, all URLs are accepted.
     *
     * @param array<int,string> $rawUrls
     *
     * @return array<int,string> Allowed URLs
     */
    private function filterAllowedUrlPath(array $rawUrls): array
    {
        if ($this->config->allowPrefixes() === []) {
            return array_values($rawUrls);
        }

        $filtered = array_filter($rawUrls, function (string $url): bool {
            foreach ($this->config->allowPrefixes() as $prefix) {
                if (is_string($prefix) && str_starts_with($url, $prefix)) {
                    return true;
                }
            }
            return false;
        });

        return array_values($filtered);
    }

    /**
     * Filters URLs using a deny-list strategy.
     *
     * URLs starting with any deny prefix are removed.
     * If no deny prefixes are configured, all URLs are kept.
     *
     * @param array<int,string> $rawUrls
     *
     * @return array<int,string> URLs with denied prefixes removed
     */
    private function filterUnneededUrls(array $rawUrls): array
    {
        if ($this->config->denyPrefixes() === []) {
            return array_values($rawUrls);
        }

        $filtered = array_filter($rawUrls, function (string $url): bool {
            foreach ($this->config->denyPrefixes() as $prefix) {
                if (str_starts_with($url, $prefix)) {
                    return false;
                }
            }
            return true;
        });

        return array_values($filtered);
    }

    /**
     * Filters URLs by checking their file extensions against a deny-list.
     *
     * @param array<int,string> $urls
     * @return array<int,string>
     */
    private function filterDeniedEndings(array $urls): array
    {
        $denyEndings = $this->config->denyEndings();

        if (empty($denyEndings)) {
            return $urls;
        }

        return array_values(array_filter($urls, function (string $url) use ($denyEndings): bool {
            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '') {
                return true;
            }

            $lowerPath = strtolower($path);
            foreach ($denyEndings as $ending) {
                if (is_string($ending) && str_ends_with($lowerPath, strtolower($ending))) {
                    return false;
                }
            }

            return true;
        }));
    }
}
