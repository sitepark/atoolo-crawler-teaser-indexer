<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Config;

use Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator\ScoreRuleConfig;

final class ContentScoringConfig
{
    /**
     * @param list<ScoreRuleConfig> $positive
     * @param list<ScoreRuleConfig> $negative
     * @param list<string>          $contentSelectors CSS selectors for the main content region, first match
     *                                                wins; empty means the whole block
     */
    public function __construct(
        public readonly int $minScore,
        public readonly array $positive = [],
        public readonly array $negative = [],
        public readonly array $contentSelectors = [],
    ) {}
}
