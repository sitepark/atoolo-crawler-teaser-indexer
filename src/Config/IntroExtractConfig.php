<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Config;

final class IntroExtractConfig
{
    /**
     * @param list<string> $opengraph
     * @param list<string> $css
     */
    public function __construct(
        public readonly bool $present,
        public readonly bool $requiredField,
        public readonly array $opengraph,
        public readonly array $css,
        public readonly int $maxChars,
    ) {}
}
