<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Services;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Domain\Crawler\Ports\RequestExecutorInterface;
use Psr\Log\LoggerInterface;
use Spatie\Robots\RobotsTxt;

final class RobotsTxtChecker implements RobotsTxtCheckerInterface
{
    /** @var array<string, RobotsTxt|null> */
    private array $cache = [];

    public function __construct(
        private readonly CrawlerConfig $config,
        private readonly RequestExecutorInterface $requestExecutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<int,string> */
    public function filterAllowed(array $urls): array
    {
        $robotsUrl = $this->config->robotsUrl();
        if ($robotsUrl === null || $robotsUrl === '') {
            return array_values(array_unique($urls));
        }

        $robots = $this->getRobots($robotsUrl);
        if ($robots === null) {
            return array_values(array_unique($urls));
        }

        $allowed = [];
        $ua = $this->config->userAgent();

        foreach ($urls as $url) {
            if ($robots->allows($url, $ua)) {
                $allowed[] = $url;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function getRobots(string $robotsUrl): ?RobotsTxt
    {
        if (array_key_exists($robotsUrl, $this->cache)) {
            return $this->cache[$robotsUrl];
        }

        $robots = null;

        try {
            $response = $this->requestExecutor->request($robotsUrl);

            if ($response !== null) {
                $content = $response->getContent(false);
                $robots  = new RobotsTxt(trim($content));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('robots.txt could not be read, defaulting to allow', [
                'robotsUrl' => $robotsUrl,
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->cache[$robotsUrl] = $robots;
    }
}
