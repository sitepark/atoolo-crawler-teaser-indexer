<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Config;

use Atoolo\Crawler\Config\CrawlingSiteConfig;
use Symfony\Contracts\Service\ResetInterface;

final class CrawlerSiteConfig implements ResetInterface
{
    private ?CrawlingSiteConfig $site = null;

    public function set(mixed $params): void
    {
        $this->site = is_array($params) && $params !== []
            ? CrawlingSiteConfig::fromArray($params)
            : null;
    }

    public function getSite(): ?CrawlingSiteConfig
    {
        return $this->site;
    }

    public function reset(): void
    {
        $this->site = null;
    }
}
