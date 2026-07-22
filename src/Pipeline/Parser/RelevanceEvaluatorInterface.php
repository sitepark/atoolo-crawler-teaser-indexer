<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Parser;

interface RelevanceEvaluatorInterface
{
    /**
     * @param array<string,mixed> $relevanceData
     */
    public function relevant(array $relevanceData): bool;
}
