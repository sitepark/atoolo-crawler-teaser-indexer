<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Symfony\Component\Yaml\Yaml;

final class SiteConfigLoader
{
    public function __construct(
        private readonly string $dir
    ) {
    }

    /**
     * @return array<mixed, mixed>
     */
    public function load(string $siteKey): array
    {
        $path = rtrim($this->dir, '/') . '/' . $siteKey . '.yaml';
        $data = Yaml::parseFile($path);

        if (is_array($data) && isset($data['parameters']) && is_array($data['parameters'])) {
            return $data['parameters'];
        }

        return [];
    }
}
