<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Symfony\Component\Yaml\Yaml;

final class SiteConfigLoader
{
    public function __construct(private readonly string $dir)
    {
    }

    public function load(string $siteKey): mixed
    {
        $path = rtrim($this->dir, '/') . '/' . $siteKey . '.yaml';
        $data = Yaml::parseFile($path);
        return $data['parameters'] ?? [];
    }
}
