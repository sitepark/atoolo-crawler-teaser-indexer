<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator;

use Atoolo\CrawlerIndexer\Config\LengthConditionConfig;

final class ScoreRuleConfig
{
    /**
     * @param list<string> $matchAny
     */
    public function __construct(
        public readonly int $score,
        public readonly array $matchAny = [],
        public readonly ?LengthConditionConfig $condition = null,
    ) {}
}
