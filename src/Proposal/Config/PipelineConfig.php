<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Config;

use Atoolo\Crawler\Proposal\Config\ContentScoringConfig;
use Atoolo\Crawler\Proposal\Config\DateTimeExtractConfig;
use Atoolo\Crawler\Proposal\Config\FieldExtractConfig;
use Atoolo\Crawler\Proposal\Config\HttpFetcherConfig;

final class PipelineConfig
{
    /**
     * @param list<array{url: string, extraction_depth: int}> $startUrls
     * @param list<string> $allowPrefixes
     * @param list<string> $denyPrefixes
     * @param list<string> $denyEndings
     * @param list<string> $forcedArticleUrls
     * @param list<string> $stripQueryParams Empty list disables query param stripping.
     */
    public function __construct(
        // Core
        public readonly string $id,
        // Robots
        public readonly bool $respectRobotsTxt,
        public readonly ?string $robotsUrl,
        // URL collector
        public readonly array $startUrls,
        public readonly string $linkSelector,
        public readonly array $allowPrefixes,
        public readonly array $denyPrefixes,
        public readonly array $denyEndings,
        public readonly array $forcedArticleUrls,
        public readonly array $stripQueryParams,
        public readonly int $maxItems,
        // Fetcher / HTTP
        public readonly HttpFetcherConfig $httpFetcherConfig,
        // Parser
        public readonly FieldExtractConfig $titleConfig,
        public readonly FieldExtractConfig $introTextConfig,
        public readonly DateTimeExtractConfig $dateTimeConfig,
        // Content scoring
        public readonly bool $contentScoringActive,
        public readonly ContentScoringConfig $contentScoringConfig,
    ) {}
}
