<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Ports;

use Symfony\Contracts\HttpClient\ResponseInterface;

interface RequestExecutorInterface
{
    public function request(string $url): ?ResponseInterface;
}
