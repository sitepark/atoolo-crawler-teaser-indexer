<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

interface RobotsTxtCheckerInterface
{
    /** @param array<int,string> $urls @return array<int,string> */
    public function filterAllowed(array $urls): array;
}
