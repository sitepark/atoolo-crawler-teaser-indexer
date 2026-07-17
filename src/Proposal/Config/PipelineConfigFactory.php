<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Config;


final class PipelineConfigFactory
{
    /**
     * @param array<string, mixed> $siteData
     */
    public function create(array $siteData): ?PipelineConfig
    {
        return null;
        //return new PipelineConfig();
    }
}
