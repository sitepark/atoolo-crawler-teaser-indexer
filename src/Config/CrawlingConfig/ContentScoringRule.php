<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config\CrawlingConfig;

final class ContentScoringRule
{
    /**
     * @param list<string>|null $matchAny
     */
    public function __construct(
        public readonly int     $score,
        public readonly ?array  $matchAny       = null,
        public readonly ?int    $bodyTextLength  = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            score:          (int) $data['score'],
            matchAny:       $data['match_any'] ?? null,
            bodyTextLength: isset($data['condition']['body_text_length'])
                                ? (int) $data['condition']['body_text_length']
                                : null,
        );
    }
}
