<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Config;

use Atoolo\CrawlerIndexer\Pipeline\Parser\ScoreRuleConfig;

final class ContentScoringConfig
{
    /**
     * @param list<ScoreRuleConfig> $positive
     * @param list<ScoreRuleConfig> $negative
     */
    public function __construct(
        public readonly int $minScore,
        public readonly array $positive = [],
        public readonly array $negative = [],
    ) {}
}
