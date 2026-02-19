<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

interface RobotsTxtCheckerInterface
{
    /** @param list<string> $urls
     * @return list<string>
     */
    public function filterAllowed(array $urls): array;
}
