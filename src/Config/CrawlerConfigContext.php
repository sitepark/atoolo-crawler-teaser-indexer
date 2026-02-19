<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Symfony\Contracts\Service\ResetInterface;

final class CrawlerConfigContext implements ResetInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    public function __construct(mixed $input)
    {
        $this->set($input);
    }

    /**
     * @param mixed $params
     */
    public function set(mixed $params): void
    {
        $this->params = is_array($params) ? $params : [];
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
