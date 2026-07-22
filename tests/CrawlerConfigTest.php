<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CrawlerConfigTest extends TestCase
{
    /** @param array<string, mixed> $params */
    private function makeConfig(array $params): PipelineConfig
    {
        $ctx = $params;
        $logger = $this->createStub(LoggerInterface::class);
        $helper = new PipelineConfigHelper($ctx, $logger);

        return new PipelineConfig($helper);
    }

    // --- categoriesId ---

    public function testCategoriesIdReturnsIntList(): void
    {
        $config = $this->makeConfig(['sp_categories' => [3, 1, 2]]);
        $this->assertSame([1, 2, 3], $config->categoriesId());
    }

    public function testCategoriesIdReturnsEmptyByDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame([], $config->categoriesId());
    }

    // --- categoriesPathId ---

    public function testCategoriesPathIdReturnsIntList(): void
    {
        $config = $this->makeConfig(['sp_categories_path' => [10, 20]]);
        $this->assertSame([10, 20], $config->categoriesPathId());
    }

    public function testCategoriesPathIdReturnsEmptyByDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame([], $config->categoriesPathId());
    }

    // --- id ---

    public function testIdReturnsString(): void
    {
        $config = $this->makeConfig(['sp_id' => 'my-crawler']);
        $this->assertSame('my-crawler', $config->id());
    }

    // --- respectRobotsTxt ---

    public function testRespectRobotsTxtDefaultFalse(): void
    {
        $config = $this->makeConfig([]);
        $this->assertFalse($config->respectRobotsTxt());
    }

    public function testRespectRobotsTxtTrue(): void
    {
        $config = $this->makeConfig(['sp_respect_robots_txt' => true]);
        $this->assertTrue($config->respectRobotsTxt());
    }

    // --- robotsUrl ---

    public function testRobotsUrlReturnsNullByDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame('', $config->robotsUrl());
    }

    public function testRobotsUrlReturnsString(): void
    {
        $config = $this->makeConfig(['sp_robots_url' => 'https://example.com/robots.txt']);
        $this->assertSame('https://example.com/robots.txt', $config->robotsUrl());
    }

    // --- startUrls ---

    public function testStartUrlsReturnsEmptyByDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame([], $config->startUrls());
    }

    public function testStartUrlsWithArrayItemAndNumericDepth(): void
    {
        $config = $this->makeConfig(['sp_start_urls' => [
            ['sp_url' => 'https://example.com/', 'sp_extraction_depth' => 3],
        ]]);
        $this->assertSame([
            ['url' => 'https://example.com/', 'extraction_depth' => 3],
        ], $config->startUrls());
    }

    public function testStartUrlsWithArrayItemWithoutDepthDefaultsToZero(): void
    {
        $config = $this->makeConfig(['sp_start_urls' => [
            ['sp_url' => 'https://example.com/'],
        ]]);
        $this->assertSame([
            ['url' => 'https://example.com/', 'extraction_depth' => 0],
        ], $config->startUrls());
    }

    public function testStartUrlsWithNonNumericDepthDefaultsToOne(): void
    {
        $config = $this->makeConfig(['sp_start_urls' => [
            ['sp_url' => 'https://example.com/', 'sp_extraction_depth' => 'deep'],
        ]]);
        $this->assertSame([
            ['url' => 'https://example.com/', 'extraction_depth' => 1],
        ], $config->startUrls());
    }

    public function testStartUrlsSkipsArrayItemWithoutSpUrl(): void
    {
        $config = $this->makeConfig(['sp_start_urls' => [
            ['other_key' => 'https://example.com/'],
        ]]);
        $this->assertSame([], $config->startUrls());
    }

    public function testStartUrlsMixedStringAndArray(): void
    {
        $config = $this->makeConfig(['sp_start_urls' => [
            ['sp_url' => 'https://example.com/news/', 'sp_extraction_depth' => 2],
        ]]);
        $this->assertSame([
            ['url' => 'https://example.com/news/', 'extraction_depth' => 2],
        ], $config->startUrls());
    }

    // --- linkSelector ---

    public function testLinkSelectorDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame('#content a[href]', $config->linkSelector());
    }

    public function testLinkSelectorCustom(): void
    {
        $config = $this->makeConfig(['sp_link_selector' => '.main a[href]']);
        $this->assertSame('.main a[href]', $config->linkSelector());
    }

    // --- allowPrefixes ---

    public function testAllowPrefixes(): void
    {
        $config = $this->makeConfig(['sp_allow_prefixes' => ['https://example.com/']]);
        $this->assertSame(['https://example.com/'], $config->allowPrefixes());
    }

    public function testAllowPrefixesEmptyByDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame([], $config->allowPrefixes());
    }

    // --- denyPrefixes ---

    public function testDenyPrefixesFiltersNonStrings(): void
    {
        $config = $this->makeConfig(['sp_deny_prefixes' => ['https://example.com/', 123, 'https://other.com/']]);
        $this->assertSame(['https://example.com/', 'https://other.com/'], $config->denyPrefixes());
    }

    public function testDenyPrefixesEmptyByDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame([], $config->denyPrefixes());
    }

    // --- denyEndings ---

    public function testDenyEndings(): void
    {
        $config = $this->makeConfig(['sp_deny_endings' => ['.pdf', '.zip']]);
        $this->assertSame(['.pdf', '.zip'], $config->denyEndings());
    }

    // --- forcedArticleUrls ---

    public function testForcedArticleUrls(): void
    {
        $config = $this->makeConfig(['sp_forced_article_urls' => ['https://example.com/forced']]);
        $this->assertSame(['https://example.com/forced'], $config->forcedArticleUrls());
    }

    // --- stripQueryParams ---

    public function testStripQueryParamsActiveDefaultFalse(): void
    {
        $config = $this->makeConfig([]);
        $this->assertFalse($config->stripQueryParamsActive());
    }

    public function testStripQueryParamsActiveTrue(): void
    {
        $config = $this->makeConfig(['sp_strip_query_params_active' => true]);
        $this->assertTrue($config->stripQueryParamsActive());
    }

    public function testStripQueryParams(): void
    {
        $config = $this->makeConfig(['sp_strip_query_params' => ['utm_source', 'utm_medium']]);
        $this->assertSame(['utm_source', 'utm_medium'], $config->stripQueryParams());
    }

    // --- maxDocument / cleanupThreshold ---

    public function testMaxDocumentDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame(100, $config->maxTeaser());
    }

    public function testMaxDocumentCustom(): void
    {
        $config = $this->makeConfig(['sp_max_teaser' => 50]);
        $this->assertSame(50, $config->maxTeaser());
    }

    public function testCleanupThresholdDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame(50, $config->cleanupThreshold());
    }

    public function testCleanupThresholdCustom(): void
    {
        $config = $this->makeConfig(['sp_cleanup_threshold' => 20]);
        $this->assertSame(20, $config->cleanupThreshold());
    }

    // --- HTTP / Fetcher ---

    public function testMaxRetryDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame(3, $config->maxRetry());
    }

    public function testDelayMsDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame(150, $config->delayMs());
    }

    public function testBackoffMsDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame(500, $config->backoffMs());
    }

    public function testParallelRequestsDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame(1, $config->parallelRequests());
    }

    public function testUserAgentDefault(): void
    {
        $config = $this->makeConfig([]);
        $this->assertSame('Atoolo/Crawler-Teaser-Indexer', $config->userAgent());
    }

    public function testUserAgentCustom(): void
    {
        $config = $this->makeConfig(['sp_user_agent' => 'MyBot/1.0']);
        $this->assertSame('MyBot/1.0', $config->userAgent());
    }

    // --- titleConfig ---

    public function testTitleConfigDefaults(): void
    {
        $config = $this->makeConfig([]);
        $titleConfig = $config->titleConfig();
        $this->assertTrue($titleConfig->present);
        $this->assertTrue($titleConfig->requiredField);
        $this->assertSame('', $titleConfig->prefix);
        $this->assertSame([], $titleConfig->opengraph);
        $this->assertSame([], $titleConfig->css);
        $this->assertSame(999, $titleConfig->maxChars);
    }

    public function testTitleConfigCustomValues(): void
    {
        $config = $this->makeConfig([
            'sp_title_prefix' => 'PRE: ',
            'sp_title_opengraph' => ['og:title'],
            'sp_title_css' => ['h1', '.title'],
            'sp_title_max_chars' => 300,
        ]);
        $titleConfig = $config->titleConfig();
        $this->assertSame('PRE: ', $titleConfig->prefix);
        $this->assertSame(['og:title'], $titleConfig->opengraph);
        $this->assertSame(['h1', '.title'], $titleConfig->css);
        $this->assertSame(300, $titleConfig->maxChars);
    }

    // --- introTextConfig ---

    public function testIntroTextConfigDefaultsToNotPresent(): void
    {
        $config = $this->makeConfig([]);
        $introConfig = $config->introTextConfig();
        $this->assertFalse($introConfig->present);
        $this->assertFalse($introConfig->requiredField);
    }

    public function testIntroTextConfigCustomValues(): void
    {
        $config = $this->makeConfig([
            'sp_introText_present' => true,
            'sp_introText_required_field' => true,
            'sp_introText_opengraph' => ['og:description'],
            'sp_introText_css' => ['.intro'],
            'sp_introText_max_chars' => 500,
        ]);
        $introConfig = $config->introTextConfig();
        $this->assertTrue($introConfig->present);
        $this->assertTrue($introConfig->requiredField);
        $this->assertSame(['og:description'], $introConfig->opengraph);
        $this->assertSame(['.intro'], $introConfig->css);
        $this->assertSame(500, $introConfig->maxChars);
    }

    // --- dateTimeConfig ---

    public function testDateTimeConfigDefaultsToNotPresent(): void
    {
        $config = $this->makeConfig([]);
        $dtConfig = $config->dateTimeConfig();
        $this->assertFalse($dtConfig->present);
        $this->assertFalse($dtConfig->requiredField);
        $this->assertTrue($dtConfig->onlyDate);
    }

    public function testDateTimeConfigCustomValues(): void
    {
        $config = $this->makeConfig([
            'sp_datetime_present' => true,
            'sp_datetime_required_field' => true,
            'sp_datetime_only_date' => false,
            'sp_datetime_opengraph' => ['article:published_time'],
            'sp_datetime_css' => ['time'],
        ]);
        $dtConfig = $config->dateTimeConfig();
        $this->assertTrue($dtConfig->present);
        $this->assertTrue($dtConfig->requiredField);
        $this->assertFalse($dtConfig->onlyDate);
        $this->assertSame(['article:published_time'], $dtConfig->opengraph);
        $this->assertSame(['time'], $dtConfig->css);
    }

    // --- introTextPresent / dateTimePresent ---

    public function testIntroTextPresentDefaultFalse(): void
    {
        $config = $this->makeConfig([]);
        $this->assertFalse($config->introTextPresent());
    }

    public function testIntroTextPresentTrue(): void
    {
        $config = $this->makeConfig(['sp_introText_present' => true]);
        $this->assertTrue($config->introTextPresent());
    }

    public function testDateTimePresentDefaultFalse(): void
    {
        $config = $this->makeConfig([]);
        $this->assertFalse($config->dateTimePresent());
    }

    public function testDateTimePresentTrue(): void
    {
        $config = $this->makeConfig(['sp_datetime_present' => true]);
        $this->assertTrue($config->dateTimePresent());
    }

    // --- contentScoring ---

    public function testContentScoringActiveDefaultFalse(): void
    {
        $config = $this->makeConfig([]);
        $this->assertFalse($config->contentScoringActive());
    }

    public function testContentScoringActiveTrue(): void
    {
        $config = $this->makeConfig(['sp_content_scoring_active' => true]);
        $this->assertTrue($config->contentScoringActive());
    }

    public function testContentScoringConfigDefaults(): void
    {
        $config = $this->makeConfig([]);
        $scoringConfig = $config->contentScoringConfig();
        $this->assertSame(4, $scoringConfig->minScore);
        $this->assertSame([], $scoringConfig->positive);
        $this->assertSame([], $scoringConfig->negative);
    }

    public function testContentScoringConfigWithRules(): void
    {
        $config = $this->makeConfig([
            'sp_content_scoring_min_score' => 5,
            'sp_content_scoring_positive' => [
                ['sp_score' => 2, 'sp_match_any' => ['news', 'sport']],
            ],
            'sp_content_scoring_negative' => [
                ['sp_score' => 1, 'sp_match_any' => ['advertisement']],
            ],
        ]);
        $scoringConfig = $config->contentScoringConfig();
        $this->assertSame(5, $scoringConfig->minScore);
        $this->assertCount(1, $scoringConfig->positive);
        $this->assertSame(2, $scoringConfig->positive[0]->score);
        $this->assertSame(['news', 'sport'], $scoringConfig->positive[0]->matchAny);
        $this->assertCount(1, $scoringConfig->negative);
        $this->assertSame(1, $scoringConfig->negative[0]->score);
    }
}
