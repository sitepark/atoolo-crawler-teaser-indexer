<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Domain\Crawler\Services;

interface RobotsTxtCheckerInterface
{
    /** @param list<string> $urls
     * @return list<string>
     */
    public function filterAllowed(array $urls): array;
}
