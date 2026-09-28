<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;

interface RelevanceEvaluatorInterface
{
    /**
     * @param array<string,mixed> $relevanceData
     */
    public function relevant(array $relevanceData, PipelineConfig $config): bool;
}
