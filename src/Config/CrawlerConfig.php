<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Atoolo\Crawler\Domain\Crawler\Services\ContentScoringConfig;
use Atoolo\Crawler\Domain\Crawler\Services\DateTimeExtractConfig;
use Atoolo\Crawler\Domain\Crawler\Services\IntroExtractConfig;
use Atoolo\Crawler\Domain\Crawler\Services\TitleExtractConfig;

final class CrawlerConfig
{
    public function __construct(
        private CrawlerConfigHelper $crawlerConfigHelper,
    ) {
    }

    // --- Category ---

    /** @return list<int> */
    public function categoriesId(): array
    {
        return $this->crawlerConfigHelper->intList('sp_categories');
    }

    /** @return list<int> */
    public function categoriesPathId(): array
    {
        return $this->crawlerConfigHelper->intList('sp_categories_path');
    }

    // --- Core / Meta ---

    public function id(): string
    {
        return $this->crawlerConfigHelper->string('sp_id');
    }

    // --- Robots ---

    public function respectRobotsTxt(): bool
    {
        return $this->crawlerConfigHelper->bool('sp_respect_robots_txt', false);
    }

    public function robotsUrl(): string
    {
        return $this->crawlerConfigHelper->string('sp_robots_url');
    }

    // --- URL Collector ---

    /**
     * @return list<array{url:string, extraction_depth:int}>
     */
    public function startUrls(): array
    {
        $raw = $this->crawlerConfigHelper->startUrlsList('sp_start_urls');

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $out[] = ['url' => $item, 'extraction_depth' => 0];
                continue;
            }

            if (is_array($item) && isset($item['sp_url']) && is_string($item['sp_url'])) {
                $depth = $item['sp_extraction_depth'] ?? 0;
                $out[] = [
                    'url' => $item['sp_url'],
                    'extraction_depth' => is_numeric($depth) ? (int) $depth : 1,
                ];
            }
        }

        return $out;
    }

    public function linkSelector(): string
    {
        return $this->crawlerConfigHelper->string('sp_link_selector', '#content a[href]');
    }

    /** @return list<string> */
    public function allowPrefixes(): array
    {
        return $this->crawlerConfigHelper->stringList('sp_allow_prefixes');
    }

    /**
     * @return list<string>
     */
    public function denyPrefixes(): array
    {
        $denyPrefixes = $this->crawlerConfigHelper->stringList('sp_deny_prefixes');

        return array_values(array_filter($denyPrefixes, 'is_string'));
    }

    /** @return list<string> */
    public function denyEndings(): array
    {
        return $this->crawlerConfigHelper->stringList('sp_deny_endings');
    }

    /** @return list<string> */
    public function forcedArticleUrls(): array
    {
        return $this->crawlerConfigHelper->stringList('sp_forced_article_urls');
    }

    public function stripQueryParamsActive(): bool
    {
        return $this->crawlerConfigHelper->bool('sp_strip_query_params_active', false);
    }

    /** @return list<string> */
    public function stripQueryParams(): array
    {
        return $this->crawlerConfigHelper->stringList('sp_strip_query_params');
    }

    public function maxTeaser(): int
    {
        return $this->crawlerConfigHelper->int('sp_max_teaser', 100);
    }

    public function cleanupThreshold(): int
    {
        return $this->crawlerConfigHelper->int('sp_cleanup_threshold', 50);
    }

    // --- Fetcher / HTTP ---

    public function maxRetry(): int
    {
        return $this->crawlerConfigHelper->int('sp_max_retry', 3);
    }

    public function delayMs(): int
    {
        return $this->crawlerConfigHelper->int('sp_delay_ms', 150);
    }

    public function backoffMs(): int
    {
        return $this->crawlerConfigHelper->int('sp_backoff_ms', 500);
    }

    public function parallelRequests(): int
    {
        return $this->crawlerConfigHelper->int('sp_parallel_requests', 1);
    }

    public function userAgent(): string
    {
        return $this->crawlerConfigHelper->string('sp_user_agent', 'Atoolo/Crawler-Teaser-Indexer');
    }

    // --- Parser: Title ---

    public function titleConfig(): TitleExtractConfig
    {
        return new TitleExtractConfig(
            present: true,
            requiredField: true,
            prefix: $this->crawlerConfigHelper->string('sp_title_prefix', ''),
            opengraph: $this->crawlerConfigHelper->stringList('sp_title_opengraph'),
            css: $this->crawlerConfigHelper->stringList('sp_title_css'),
            maxChars: $this->crawlerConfigHelper->int('sp_title_max_chars', 999),
        );
    }

    public function introTextConfig(): IntroExtractConfig
    {
        return new IntroExtractConfig(
            present: $this->crawlerConfigHelper->bool('sp_introText_present', false),
            requiredField: $this->crawlerConfigHelper->bool('sp_introText_required_field', false),
            opengraph: $this->crawlerConfigHelper->stringList('sp_introText_opengraph'),
            css: $this->crawlerConfigHelper->stringList('sp_introText_css'),
            maxChars: $this->crawlerConfigHelper->int('sp_introText_max_chars', 999),
        );
    }

    public function dateTimeConfig(): DateTimeExtractConfig
    {
        return new DateTimeExtractConfig(
            present: $this->crawlerConfigHelper->bool('sp_datetime_present', false),
            requiredField: $this->crawlerConfigHelper->bool('sp_datetime_required_field', false),
            onlyDate: $this->crawlerConfigHelper->bool('sp_datetime_only_date', true),
            opengraph: $this->crawlerConfigHelper->stringList('sp_datetime_opengraph'),
            css: $this->crawlerConfigHelper->stringList('sp_datetime_css'),
        );
    }

    // --- Parser: IntroText ---

    public function introTextPresent(): bool
    {
        return $this->crawlerConfigHelper->bool('sp_introText_present', false);
    }

    // --- Parser: Datetime ---

    public function dateTimePresent(): bool
    {
        return $this->crawlerConfigHelper->bool('sp_datetime_present', false);
    }

    // --- content Scoring (The goal is to keep only the most relevant teasers when there are too many of them.) ---

    public function contentScoringActive(): bool
    {
        return $this->crawlerConfigHelper->bool('sp_content_scoring_active', false);
    }

    public function contentScoringConfig(): ContentScoringConfig
    {
        $minScore = $this->crawlerConfigHelper->int('sp_content_scoring_min_score', 4);

        $positive = $this->crawlerConfigHelper->readScoreRules('sp_content_scoring_positive');
        $negative = $this->crawlerConfigHelper->readScoreRules('sp_content_scoring_negative');

        return new ContentScoringConfig(
            minScore: $minScore,
            positive: $positive,
            negative: $negative,
        );
    }
}
