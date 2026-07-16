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
        public readonly bool $active = false,
        public readonly int $minScore = 0,
        public readonly array $positive = [],
        public readonly array $negative = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{score: int, match_any?: list<string>, condition?: array{body_text_length?: int}}> $positive */
        $positive = $data['sp_content_scoring_positive'] ?? [];

        /** @var list<array{score: int, match_any?: list<string>, condition?: array{body_text_length?: int}}> $negative */
        $negative = $data['sp_content_scoring_negative'] ?? [];
        return new self(
            positive: array_map(
                static fn(array $r) => ContentScoringRule::fromArray($r),
                $positive,
            ),
            negative: array_map(
                static fn(array $r) => ContentScoringRule::fromArray($r),
                $negative,
            ),
        );
    }
}
