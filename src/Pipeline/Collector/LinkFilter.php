<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Collector;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;

/**
 * All rules for following a link, in one place:
 *
 * 1. `sp_allow_prefixes` - if set, the URL must start with one of them
 * 2. `sp_deny_prefixes`  - the URL must not start with any of them
 * 3. deny endings        - bundle-wide `atoolo.crawler.deny_endings` plus
 *                          `sp_deny_endings`, compared case-insensitively
 *                          against the path
 * 4. robots.txt          - if `sp_respect_robots_txt` is enabled
 *
 * Prefixes are compared against canonical URLs (lowercase scheme and host,
 * no default port), so they should be configured in that form.
 */
final class LinkFilter implements LinkFilterInterface
{
    /**
     * @param list<string> $denyEndings bundle-wide deny endings
     */
    public function __construct(
        private readonly RobotsTxtCheckerInterface $robotsTxtChecker,
        private readonly array $denyEndings,
    ) {}

    public function filter(array $urls, PipelineConfig $config): array
    {
        $allowPrefixes = $config->allowPrefixes();
        $denyPrefixes = $config->denyPrefixes();
        $denyEndings = array_values(array_unique(array_map(
            'strtolower',
            [...$this->denyEndings, ...$config->denyEndings()],
        )));

        $urls = array_values(array_filter(
            $urls,
            fn(string $url): bool => ([] === $allowPrefixes || $this->startsWithAny($url, $allowPrefixes))
                && !$this->startsWithAny($url, $denyPrefixes)
                && !$this->hasDeniedEnding($url, $denyEndings),
        ));

        if ($config->respectRobotsTxt() && [] !== $urls) {
            $urls = $this->robotsTxtChecker->filterAllowed($urls, $config);
        }

        return $urls;
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

    /**
     * @param list<string> $denyEndings lowercase
     */
    private function hasDeniedEnding(string $url, array $denyEndings): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            return false;
        }

        $path = strtolower($path);
        foreach ($denyEndings as $ending) {
            if ('' !== $ending && str_ends_with($path, $ending)) {
                return true;
            }
        }

        return false;
    }
}
