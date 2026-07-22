<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Config;

use Psr\Log\LoggerInterface;

/**
 * Builds an immutable {@see PipelineConfig} for a single crawling site.
 *
 * The raw sp_* array is validated once here: an invalid config (e.g. a
 * missing "sp_id") raises an \InvalidArgumentException instead of yielding
 * a half-valid object. The per-site loop (command / message handler) is
 * expected to catch this and skip the offending site.
 */
final class PipelineConfigFactory
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $siteData
     */
    public function create(array $siteData): PipelineConfig
    {
        $id = $siteData['sp_id'] ?? null;

        if (!is_string($id) || '' === $id) {
            throw new \InvalidArgumentException('Site config is missing required field "sp_id".');
        }

        return new PipelineConfig(new PipelineConfigHelper($siteData, $this->logger));
    }
}
