<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Services\TeaserRelevanceEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TeaserRelevanceEvaluatorTest extends TestCase
{
    private function makeEvaluator(array $config): TeaserRelevanceEvaluator
    {
        $logger = $this->createStub(LoggerInterface::class);
        $ctx = new CrawlerConfigContext($config);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        return new TeaserRelevanceEvaluator($crawlerConfig);
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [],
            'sp_content_scoring_negative' => [],
            'sp_forced_article_urls' => [],
        ], $overrides);
    }

    public function testForcedArticleUrlIsAlwaysRelevant(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_forced_article_urls' => ['https://example.com/forced'],
            'sp_content_scoring_min_score' => 999,
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/forced',
            'title' => 'Test',
        ]);

        $this->assertTrue($result);
    }

    public function testNotForcedAndScoreBelowMinScoreIsNotRelevant(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [],
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'Test',
        ]);

        $this->assertFalse($result);
    }

    public function testPositiveRuleMatchBoostsScoreAboveMinScore(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['news']],
            ],
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'Breaking News',
        ]);

        $this->assertTrue($result);
    }

    public function testPositiveRuleNoMatchLeavesBelowMinScore(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['news']],
            ],
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'Product Page',
        ]);

        $this->assertFalse($result);
    }

    public function testNegativeRuleMatchReducesScore(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['article']],
            ],
            'sp_content_scoring_negative' => [
                ['sp_score' => -5, 'sp_match_any' => ['sponsored']],
            ],
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'Sponsored article',
        ]);

        $this->assertFalse($result);
    }

    public function testFragmentUrlReducesScoreByTwo(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['news']],
            ],
        ]));

        // score = 5 (positive) - 2 (fragment) = 3 < 4 → not relevant
        $result = $evaluator->relevant([
            'url' => 'https://example.com/page#section',
            'title' => 'Breaking News',
        ]);

        $this->assertFalse($result);
    }

    public function testFragmentUrlWithHighEnoughScoreIsStillRelevant(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 10, 'sp_match_any' => ['news']],
            ],
        ]));

        // score = 10 (positive) - 2 (fragment) = 8 >= 4 → relevant
        $result = $evaluator->relevant([
            'url' => 'https://example.com/page#section',
            'title' => 'Breaking News',
        ]);

        $this->assertTrue($result);
    }

    public function testMatchIsNormalizedCaseInsensitive(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['NEWS']],
            ],
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'breaking news',
        ]);

        $this->assertTrue($result);
    }

    public function testIntroTextIsAlsoSearchedForMatches(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['keyword']],
            ],
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'Generic Title',
            'introText' => 'This contains the keyword here',
        ]);

        $this->assertTrue($result);
    }

    public function testBodyTextLengthConditionMatchesShortText(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_negative' => [
                [
                    'sp_score' => -10,
                    'sp_condition' => ['sp_body_text_length' => 50],
                ],
            ],
        ]));

        // Short body text (less than 50 chars) triggers negative rule
        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'Test',
            'introText' => 'Short.',
        ]);

        $this->assertFalse($result);
    }

    public function testBodyTextLengthConditionDoesNotMatchLongText(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['article']],
            ],
            'sp_content_scoring_negative' => [
                [
                    'sp_score' => -10,
                    'sp_condition' => ['sp_body_text_length' => 10],
                ],
            ],
        ]));

        // Long enough intro text → condition does NOT match → only positive rule applies
        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'article',
            'introText' => 'This is a long enough text that exceeds the threshold.',
        ]);

        $this->assertTrue($result);
    }

    public function testHtmlBodyContentIsSearchedForMatches(): void
    {
        $html = '<html><body><main>This page is about technology news.</main></body></html>';

        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 4,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['technology']],
            ],
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'Generic Title',
            'html' => $html,
        ]);

        $this->assertTrue($result);
    }

    public function testScoreExactlyAtMinScoreIsRelevant(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 5,
            'sp_content_scoring_positive' => [
                ['sp_score' => 5, 'sp_match_any' => ['news']],
            ],
        ]));

        $result = $evaluator->relevant([
            'url' => 'https://example.com/page',
            'title' => 'Breaking News',
        ]);

        $this->assertTrue($result);
    }
}
