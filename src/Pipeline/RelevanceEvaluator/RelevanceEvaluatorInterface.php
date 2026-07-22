<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator;

interface RelevanceEvaluatorInterface
{
    /**
     * @param array<string,mixed> $relevanceData
     */
    public function relevant(array $relevanceData): bool;
}
