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
        return $this->crawlerConfigHelper->string('atoolo.crawler.id');
    }

    // --- Robots ---

    public function respectRobotsTxt(): bool
    {
        return $this->crawlerConfigHelper->bool('atoolo.crawler.respect_robots_txt', false);
    }

    public function robotsUrl(): ?string
    {
        return $this->crawlerConfigHelper->nullableString('atoolo.crawler.robots_url');
    }

    // --- URL Collector ---

    /**
     * @return list<array{url:string, extraction_depth:int}>
     */
    public function startUrls(): array
    {
        $raw = $this->crawlerConfigHelper->intStringList('atoolo.crawler.start_urls', []);

        if (!is_array($raw)) {
            return [];
        }

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
                    'extraction_depth' => is_numeric($depth) ? (int) $depth : 0,
                ];
            }
        }

        return $out;
    }

    public function linkSection(): string
    {
        return $this->crawlerConfigHelper->string('atoolo.crawler.link_section', '#content');
    }

    public function linkSelector(): string
    {
        return $this->crawlerConfigHelper->string('atoolo.crawler.link_selector', 'a[href]');
    }

    /** @return list<string> */
    public function allowPrefixes(): array
    {
        return $this->crawlerConfigHelper->intStringList('atoolo.crawler.allow_prefixes');
    }

    /** @return list<string> */
    public function denyPrefixes(): array
    {
        return $this->crawlerConfigHelper->intStringList('atoolo.crawler.deny_prefixes');
    }

    /** @return list<string> */
    public function denyEndings(): array
    {
        return $this->crawlerConfigHelper->intStringList('atoolo.crawler.deny_endings');
    }

    /** @return list<string> */
    public function forcedArticleUrls(): array
    {
        return $this->crawlerConfigHelper->intStringList('atoolo.crawler.forced_article_urls');
    }

    public function stripQueryParamsActive(): bool
    {
        return $this->crawlerConfigHelper->bool('atoolo.crawler.strip_query_params_active', false);
    }

    /** @return list<string> */
    public function stripQueryParams(): array
    {
        return $this->crawlerConfigHelper->intStringList('atoolo.crawler.strip_query_params');
    }

    public function maxTeaser(): int
    {
        return $this->crawlerConfigHelper->int('atoolo.crawler.max_teaser', 100);
    }

    // --- Fetcher / HTTP ---

    public function maxRetry(): int
    {
        return $this->crawlerConfigHelper->int('atoolo.crawler.max_retry', 3);
    }

    /** @return list<int> */
    public function retryStatusCodes(): array
    {
        return $this->crawlerConfigHelper->intStringList('atoolo.crawler.retry_status_codes', []);
    }

    public function delayMs(): int
    {
        return $this->crawlerConfigHelper->int('atoolo.crawler.delay_ms', 0);
    }

    public function concurrencyPerHost(): int
    {
        return $this->crawlerConfigHelper->int('atoolo.crawler.concurrency_per_host', 1);
    }

    public function userAgent(): string
    {
        return $this->crawlerConfigHelper->string('atoolo.crawler.user_agent', 'Crawler/1.0');
    }

    // --- Parser: Title ---

    public function titleConfig(): FieldExtractConfig
    {
        return new FieldExtractConfig(
            present: $this->crawlerConfigHelper->bool('atoolo.crawler.title.present', true),
            requiredField: true,
            prefix: $this->crawlerConfigHelper->string('atoolo.crawler.title.prefix', ""),
            opengraph: $this->crawlerConfigHelper->intStringList('atoolo.crawler.title.opengraph'),
            css: $this->crawlerConfigHelper->intStringList('atoolo.crawler.title.css'),
            maxChars: $this->crawlerConfigHelper->int('atoolo.crawler.title.max_chars', 120),
        );
    }

    public function introTextConfig(): FieldExtractConfig
    {
        return new FieldExtractConfig(
            present: $this->crawlerConfigHelper->bool('atoolo.crawler.introText.present', false),
            requiredField: $this->crawlerConfigHelper->bool('atoolo.crawler.introText.required_field', false),
            prefix: $this->crawlerConfigHelper->string('atoolo.crawler.title.prefix', ""),
            opengraph: $this->crawlerConfigHelper->intStringList('atoolo.crawler.introText.opengraph'),
            css: $this->crawlerConfigHelper->intStringList('atoolo.crawler.introText.css'),
            maxChars: $this->crawlerConfigHelper->int('atoolo.crawler.introText.max_chars', 120),
        );
    }

    public function dateTimeConfig(): DateTimeExtractConfig
    {
        return new DateTimeExtractConfig(
            present: $this->crawlerConfigHelper->bool('atoolo.crawler.datetime.present', false),
            requiredField: $this->crawlerConfigHelper->bool('atoolo.crawler.introText.required_field', false),
            onlyDate: $this->crawlerConfigHelper->bool('atoolo.crawler.datetime.only-date', true),
            opengraph: $this->crawlerConfigHelper->intStringList('atoolo.crawler.datetime.opengraph'),
            css: $this->crawlerConfigHelper->intStringList('atoolo.crawler.datetime.css'),
        );
    }

    // --- Parser: IntroText (dein introText.*) ---

    public function introTextPresent(): bool
    {
        return $this->crawlerConfigHelper->bool('atoolo.crawler.introText.present', false);
    }

    // --- Parser: Datetime ---

    public function datetimePresent(): bool
    {
        return $this->crawlerConfigHelper->bool('atoolo.crawler.datetime.present', false);
    }

    // --- Content Scoring (Bürgernutzen-Score) ---

    public function contentScoringActive(): bool
    {
        return $this->crawlerConfigHelper->bool('atoolo.crawler.content_scoring.active', false);
    }

    public function contentScoringConfig(): ContentScoringConfig
    {
        $minScore = $this->crawlerConfigHelper->int('atoolo.crawler.content_scoring.min_score', 4);

        $positive = $this->crawlerConfigHelper->readScoreRules('atoolo.crawler.content_scoring.positive');
        $negative = $this->crawlerConfigHelper->readScoreRules('atoolo.crawler.content_scoring.negative');

        return new ContentScoringConfig(
            minScore: $minScore,
            positive: $positive,
            negative: $negative,
        );
    }
}
