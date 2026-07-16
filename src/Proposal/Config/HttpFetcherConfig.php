<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Config;

final class HttpFetcherConfig
{
    public function __construct(
        public readonly string $userAgent,
        public readonly int $maxRetry,
        public readonly int $delayMs,
        public readonly int $parallelRequests,
    ) {}
}
