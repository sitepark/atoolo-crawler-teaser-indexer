<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Symfony\Contracts\Service\ResetInterface;

final class CrawlerConfigContext implements ResetInterface
{
    private array $params = [];


    public function set(array $params): void
    {
        $this->params = $params;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function reset(): void
    {
        $this->params = [];
    }
}
