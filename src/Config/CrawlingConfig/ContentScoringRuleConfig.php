<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config\CrawlingConfig;

final class ContentScoringConfig
{
    /**
     * @param list<ContentScoringRule> $positive
     * @param list<ContentScoringRule> $negative
     */
    public function __construct(
        public readonly bool   $active   = false,
        public readonly int    $minScore = 0,
        public readonly array  $positive = [],
        public readonly array  $negative = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            positive: array_map(
                static fn(array $r) => ContentScoringRule::fromArray($r),
                $data['sp_content_scoring_positive'] ?? [],
            ),
            negative: array_map(
                static fn(array $r) => ContentScoringRule::fromArray($r),
                $data['sp_content_scoring_negative'] ?? [],
            ),
        );
    }
}
