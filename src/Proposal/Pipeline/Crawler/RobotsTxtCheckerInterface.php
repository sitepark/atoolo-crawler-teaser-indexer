<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Pipeline\Crawler;

interface RobotsTxtCheckerInterface
{
    public function isAllowed(string $url): bool;
}
