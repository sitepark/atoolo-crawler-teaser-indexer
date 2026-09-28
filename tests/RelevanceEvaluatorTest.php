<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Dto\ExtractedData;
use Atoolo\CrawlerIndexer\Pipeline\Parser\FieldSource;
use Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator\RelevanceEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DomCrawler\Crawler;

final class RelevanceEvaluatorTest extends TestCase
{
    private PipelineConfig $config;

    private function makeEvaluator(array $config): RelevanceEvaluator
    {
        $logger = $this->createStub(LoggerInterface::class);
        $ctx = $config;
        $helper = new PipelineConfigHelper($ctx, $logger);
        $this->config = new PipelineConfig($helper);

        return new RelevanceEvaluator();
    }

    /**
     * The evaluator reads the main content from the parsed page, so the test
     * data's `html` becomes a FieldSource; without it the page is empty.
     *
     * @param array{url: string, title: string, introText?: string, html?: string} $data
     */
    private function relevant(RelevanceEvaluator $evaluator, array $data): bool
    {
        $entry = new ExtractedData($data['url'], $data['title'], $data['introText'] ?? null);
        $source = new FieldSource(
            new Crawler($data['html'] ?? '<html><body></body></html>'),
            new NullLogger(),
        );

        return $evaluator->relevant($entry, $source, $this->config);
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

        $result = $this->relevant($evaluator, [
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

        $result = $this->relevant($evaluator, [
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

        $result = $this->relevant($evaluator, [
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

        $result = $this->relevant($evaluator, [
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

        $result = $this->relevant($evaluator, [
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
        $result = $this->relevant($evaluator, [
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
        $result = $this->relevant($evaluator, [
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

        $result = $this->relevant($evaluator, [
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

        $result = $this->relevant($evaluator, [
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
        $result = $this->relevant($evaluator, [
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
        $result = $this->relevant($evaluator, [
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

        $result = $this->relevant($evaluator, [
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

        $result = $this->relevant($evaluator, [
            'url' => 'https://example.com/page',
            'title' => 'Breaking News',
        ]);

        $this->assertTrue($result);
    }

    // --- Main content: visible text only, selector priority ---

    /**
     * @return iterable<string, array{string}>
     */
    public static function invisibleKeywordProvider(): iterable
    {
        yield 'attribute' => ['<html><body><main><div class="technology">Hallo</div></main></body></html>'];
        yield 'script' => ['<html><body><main>Hallo<script>var technology = 1;</script></main></body></html>'];
        yield 'style' => ['<html><body><main><style>.technology{}</style>Hallo</main></body></html>'];
    }

    /**
     * The old fallback scored the raw HTML, so keywords matched class names,
     * scripts and styles.
     *
     * @dataProvider invisibleKeywordProvider
     */
    public function testKeywordsInMarkupOrScriptsDoNotCount(string $html): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 1,
            'sp_content_scoring_positive' => [['sp_match_any' => ['technology'], 'sp_score' => 5]],
        ]));

        $this->assertFalse($this->relevant($evaluator, [
            'url' => 'https://example.com/page',
            'title' => 'Generic Title',
            'html' => $html,
        ]));
    }

    /**
     * Navigation and footer stay out when the configured content region exists.
     */
    public function testContentSelectorScoresOnlyTheMainContent(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_relevance_content_selector' => ['main'],
            'sp_content_scoring_min_score' => 1,
            'sp_content_scoring_positive' => [['sp_match_any' => ['technology'], 'sp_score' => 5]],
        ]));

        $this->assertFalse($this->relevant($evaluator, [
            'url' => 'https://example.com/page',
            'title' => 'Generic Title',
            'html' => '<html><body><nav>technology</nav><main>Sport</main><footer>technology</footer></body></html>',
        ]));
    }

    public function testFallsBackToTheWholeBlockWithoutMatchingSelector(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_relevance_content_selector' => ['main'],
            'sp_content_scoring_min_score' => 1,
            'sp_content_scoring_positive' => [['sp_match_any' => ['technology'], 'sp_score' => 5]],
        ]));

        $this->assertTrue($this->relevant($evaluator, [
            'url' => 'https://example.com/page',
            'title' => 'Generic Title',
            'html' => '<html><body><div>technology news</div></body></html>',
        ]));
    }

    public function testWithoutContentSelectorTheWholeBlockIsScored(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 1,
            'sp_content_scoring_positive' => [['sp_match_any' => ['technology'], 'sp_score' => 5]],
        ]));

        $this->assertTrue($this->relevant($evaluator, [
            'url' => 'https://example.com/page',
            'title' => 'Generic Title',
            'html' => '<html><body><nav>technology</nav><main>Sport</main></body></html>',
        ]));
    }

    /**
     * The first selector of the priority list that matches wins.
     */
    public function testFirstMatchingContentSelectorWins(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_relevance_content_selector' => ['.does-not-exist', '.content', 'main'],
            'sp_content_scoring_min_score' => 1,
            'sp_content_scoring_positive' => [['sp_match_any' => ['technology'], 'sp_score' => 5]],
        ]));

        // .content comes before main in the list.
        $this->assertTrue($this->relevant($evaluator, [
            'url' => 'https://example.com/page',
            'title' => 'Generic Title',
            'html' => '<html><body><main>Sport</main><div class="content">technology</div></body></html>',
        ]));
    }

    /**
     * The length condition counts visible text, not markup.
     */
    public function testBodyTextLengthConditionIgnoresMarkup(): void
    {
        $evaluator = $this->makeEvaluator($this->baseConfig([
            'sp_content_scoring_min_score' => 0,
            'sp_content_scoring_negative' => [
                ['sp_match_any' => [], 'sp_score' => -5, 'sp_condition' => ['sp_body_text_length' => 20]],
            ],
        ]));

        $markup = str_repeat('<span class="very-long-class-name"></span>', 10);

        $this->assertFalse($this->relevant($evaluator, [
            'url' => 'https://example.com/page',
            'title' => 'Test',
            'html' => '<html><body><main>Kurz.' . $markup . '</main></body></html>',
        ]));
    }
}
