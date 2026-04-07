<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Atoolo\Crawler\Domain\Crawler\Services\FieldExtractConfig;
use Atoolo\Crawler\Domain\Crawler\Services\DateTimeExtractConfig;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Services\ContentScoringConfig;

final class CrawlerConfig
{
    public function __construct(
        private CrawlerConfigHelper $crawlerConfigHelper
    ) {
    }

    // --- Core / Meta ---

    public function id(): string
    {
        return $this->crawlerConfigHelper->string('id');
    }

    // --- Robots ---

    public function respectRobotsTxt(): bool
    {
        return $this->crawlerConfigHelper->bool('respect_robots_txt', false);
    }

    public function robotsUrl(): ?string
    {
        return $this->crawlerConfigHelper->nullableString('robots_url');
    }

    // --- URL Collector ---

    /**
     * @return list<array{url:string, extraction_depth:int}>
     */
    public function startUrls(): array
    {
        $raw = $this->crawlerConfigHelper->intStringList('start_urls');

        $out = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $out[] = ['url' => $item, 'extraction_depth' => 0];
                continue;
            }

            if (is_array($item) && isset($item['url']) && is_string($item['url'])) {
                $depth = $item['extraction_depth'] ?? 0;
                $out[] = [
                    'url' => $item['url'],
                    'extraction_depth' => is_numeric($depth) ? (int) $depth : 1,
                ];
            }
        }

        return $out;
    }

    public function linkSelector(): string
    {
        return $this->crawlerConfigHelper->string('link_selector', 'a[href]');
    }

    /** @return list<mixed> */
    public function allowPrefixes(): array
    {
        return $this->crawlerConfigHelper->intStringList('allow_prefixes');
    }

    /**
     * @return list<string>
     */
    public function denyPrefixes(): array
    {
        $denyPrefixes = $this->crawlerConfigHelper->intStringList('deny_prefixes');
        return array_values(array_filter($denyPrefixes, 'is_string'));
    }

    /** @return list<mixed> */
    public function denyEndings(): array
    {
        return $this->crawlerConfigHelper->intStringList('deny_endings');
    }

    /** @return list<mixed> */
    public function forcedArticleUrls(): array
    {
        return $this->crawlerConfigHelper->intStringList('forced_article_urls');
    }

    public function stripQueryParamsActive(): bool
    {
        return $this->crawlerConfigHelper->bool('strip_query_params_active', false);
    }

    /** @return list<string> */
    public function stripQueryParams(): array
    {
        return $this->crawlerConfigHelper->stringList('strip_query_params');
    }

    public function maxTeaser(): int
    {
        return $this->crawlerConfigHelper->int('max_teaser', 100);
    }

    // --- Fetcher / HTTP ---

    public function maxRetry(): int
    {
        return $this->crawlerConfigHelper->int('max_retry', 3);
    }

    public function delayMs(): int
    {
        return $this->crawlerConfigHelper->int('delay_ms', 0);
    }

    public function concurrencyPerHost(): int
    {
        return $this->crawlerConfigHelper->int('concurrency_per_host', 1);
    }

    public function userAgent(): string
    {
        return $this->crawlerConfigHelper->string('user_agent', 'Crawler/1.0');
    }

    // --- Parser: Title ---

    public function titleConfig(): FieldExtractConfig
    {
        return new FieldExtractConfig(
            present: $this->crawlerConfigHelper->bool('title_present', true),
            requiredField: true,
            prefix: $this->crawlerConfigHelper->string('title_prefix', ""),
            opengraph: $this->crawlerConfigHelper->stringList('title_opengraph'),
            css: $this->crawlerConfigHelper->stringList('title_css'),
            maxChars: $this->crawlerConfigHelper->int('title_max_chars', 120),
        );
    }

    public function introTextConfig(): FieldExtractConfig
    {
        return new FieldExtractConfig(
            present: $this->crawlerConfigHelper->bool('introText_present', false),
            requiredField: $this->crawlerConfigHelper->bool('introText_required_field', false),
            prefix: "",/* $this->crawlerConfigHelper->string('introText.prefix', ""), */
            opengraph: $this->crawlerConfigHelper->stringList('introText_opengraph'),
            css: $this->crawlerConfigHelper->stringList('introText_css'),
            maxChars: $this->crawlerConfigHelper->int('introText_max_chars', 120),
        );
    }

    public function dateTimeConfig(): DateTimeExtractConfig
    {
        return new DateTimeExtractConfig(
            present: $this->crawlerConfigHelper->bool('datetime_present', false),
            requiredField: $this->crawlerConfigHelper->bool('datetime_required_field', false),
            onlyDate: $this->crawlerConfigHelper->bool('datetime_only_date', true),
            opengraph: $this->crawlerConfigHelper->stringList('datetime_opengraph'),
            css: $this->crawlerConfigHelper->stringList('datetime_css'),
        );
    }

    // --- Parser: IntroText (dein introText.*) ---

    public function introTextPresent(): bool
    {
        return $this->crawlerConfigHelper->bool('introText_present', false);
    }

    // --- Parser: Datetime ---

    public function datetimePresent(): bool
    {
        return $this->crawlerConfigHelper->bool('datetime_present', false);
    }

    // --- Content Scoring (Bürgernutzen-Score) ---

    public function contentScoringActive(): bool
    {
        return $this->crawlerConfigHelper->bool('content_scoring_active', false);
    }

    public function contentScoringConfig(): ContentScoringConfig
    {
        $minScore = $this->crawlerConfigHelper->int('content_scoring_min_score', 4);

        $positive = $this->crawlerConfigHelper->readScoreRules('content_scoring_positive');
        $negative = $this->crawlerConfigHelper->readScoreRules('content_scoring_negative');

        return new ContentScoringConfig(
            minScore: $minScore,
            positive: $positive,
            negative: $negative,
        );
    }
}
