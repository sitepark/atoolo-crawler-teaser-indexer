<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config\CrawlingConfig;

final class StartUrl
{
    public function __construct(
        public readonly string $url,
        public readonly string $extractionDepth,
    ) {
    }

    /**
     * @param array{url: string, extraction_depth: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            url:            $data['url'],
            extractionDepth: $data['extraction_depth'] ?? 0,
        );
    }
}
