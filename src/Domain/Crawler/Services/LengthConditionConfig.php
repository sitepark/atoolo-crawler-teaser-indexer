<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Domain\Crawler\Services;

final class LengthConditionConfig
{
    public function __construct(
        public readonly ?int $bodyTextLengthLt = null,
    ) {}
}
