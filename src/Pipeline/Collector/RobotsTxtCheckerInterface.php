<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Collector;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;

interface RobotsTxtCheckerInterface
{
    /** @param list<string> $urls
     * @return list<string>
     */
    public function filterAllowed(array $urls, PipelineConfig $config): array;
}
