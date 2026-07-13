<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Atoolo\Crawler\Config\CrawlingConfig\ContentScoringConfig;
use Atoolo\Crawler\Config\CrawlingConfig\StartUrl;
use Atoolo\Crawler\Domain\Crawler\Services\DateTimeExtractConfig;
use Atoolo\Crawler\Domain\Crawler\Services\IntroExtractConfig;
use Atoolo\Crawler\Domain\Crawler\Services\TitleExtractConfig;

final class CrawlingSiteConfig
{
    /**
     * @param list<int>    $categoriesId
     * @param list<int>    $categoriesPathId
     * @param list<StartUrl> $startUrls
     * @param list<string> $allowedPrefixes
     * @param list<string> $denyedPrefixes
     * @param list<string> $denyedEndings
     * @param list<string> $forcedArticleUrls
     * @param list<string> $stripQueryParams
     */
    public function __construct(
        public readonly array                 $categoriesId,
        public readonly array                 $categoriesPathId,
        public readonly string                $id,
        public readonly bool                  $respectRobotsTxt,
        public readonly string                $robotsUrl,
        public readonly int                   $maxTeaser,
        public readonly int                   $cleanupThreshold,
        public readonly int                   $maxRetry,
        public readonly int                   $parallelRequests,
        public readonly int                   $delayMs,
        public readonly int                   $backoffMs,
        public readonly string                $userAgent,
        public readonly array                 $startUrls,
        public readonly string                $linkSelector,
        public readonly array                 $allowedPrefixes,
        public readonly array                 $denyedPrefixes,
        public readonly array                 $denyedEndings,
        public readonly array                 $forcedArticleUrls,
        public readonly bool                  $stripQueryParamsActive,
        public readonly array                 $stripQueryParams,
        public readonly TitleExtractConfig    $titleConfig,
        public readonly IntroExtractConfig    $introTextConfig,
        public readonly DateTimeExtractConfig $dateTimeConfig,
        public readonly bool                  $contentScoringActive,
        public readonly int                   $contentScoringMinScore,
        public readonly ContentScoringConfig  $contentScoring,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            categoriesId:           $data['sp_categories']              ?? [],
            categoriesPathId:       $data['sp_categories_path']         ?? [],
            id:                     $data['sp_id'],
            respectRobotsTxt:       $data['sp_respect_robots_txt']      ?? false,
            robotsUrl:              $data['sp_robots_url']              ?? '',
            maxTeaser:              $data['sp_max_teaser']              ?? 1000,
            cleanupThreshold:       $data['sp_cleanup_threshold']       ?? 50,
            maxRetry:               $data['sp_max_retry']               ?? 2,
            parallelRequests:       $data['sp_parallel_requests']       ?? 1,
            delayMs:                $data['sp_delay_ms']                ?? 500,
            backoffMs:              $data['sp_backoff_ms']              ?? 500,
            userAgent:              $data['sp_user_agent']              ?? 'Atoolo/Crawler-Teaser-Indexer',
            startUrls:              array_map(
                                        static fn(array $u) => StartUrl::fromArray($u),
                                        $data['start_urls'] ?? [],
                                    ),
            linkSelector:           $data['sp_link_selector']             ?? '#content a[href]',
            allowedPrefixes:        $data['sp_allow_prefixes']            ?? [],
            denyedPrefixes:         $data['sp_deny_prefixes']             ?? [],
            denyedEndings:          $data['sp_deny_endings']              ?? [],
            forcedArticleUrls:      $data['sp_forced_article_urls']       ?? [],
            stripQueryParamsActive: $data['sp_strip_query_params_active'] ?? false,
            stripQueryParams:       $data['sp_strip_query_params']        ?? [],
            titleConfig:            new TitleExtractConfig(
                                        present:       true,
                                        requiredField: true,
                                        prefix:        $data['sp_title_prefix']           ?? '',
                                        opengraph:     $data['sp_title_opengraph']        ?? [],
                                        css:           $data['sp_title_css']              ?? [],
                                        maxChars:      (int) ($data['sp_title_max_chars'] ?? 999),
            ),
            introTextConfig:        new IntroExtractConfig(
                                        present:       $data['sp_introText_present']           ?? false,
                                        requiredField: $data['sp_required_field']              ?? false,
                                        opengraph:     $data['sp_introText_opengraph']         ?? [],
                                        css:           $data['sp_introText_css']               ?? [],
                                        maxChars:      isset($data['sp_introText_max_chars'])
                                                        ? (int) $data['sp_introText_max_chars' ?? 999]
                                                        : null,
            ),
            dateTimeConfig:         new DateTimeExtractConfig(
                                        present:       $data['sp_datetime_present']        ?? false,
                                        requiredField: $data['sp_datetime_required_field'] ?? false,
                                        onlyDate:      $data['sp_datetime_only_date']      ?? false,
                                        opengraph:     $data['sp_datetime_opengraph']      ?? [],
                                        css:           $data['sp_datetime_css']            ?? [],
            ),
            contentScoringActive:   $data['sp_content_scoring_active']    ?? false,
            contentScoringMinScore: $data['sp_content_scoring_min_score'] ?? 4,
            contentScoring:         ContentScoringConfig::fromArray($data),
        );
    }
}
