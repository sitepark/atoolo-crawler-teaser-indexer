<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Domain\Crawler\Services;

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
