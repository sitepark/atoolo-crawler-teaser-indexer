<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

interface TeaserRelevanceEvaluatorInterface
{
    /**
     * @param array<string,mixed> $relevanceData
     */
    public function relevant(array $relevanceData): bool;
}
