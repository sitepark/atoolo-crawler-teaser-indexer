<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

final class DateTimeExtractConfig
{
    /**
     * @param list<string> $opengraph
     * @param list<string> $css
     */
    public function __construct(
        public readonly bool $present,
        public readonly bool $requiredField,
        public readonly bool $onlyDate,
        public readonly array $opengraph,
        public readonly array $css,
    ) {
    }
}
