<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Collector;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Ports\RequestExecutorInterface;
use Psr\Log\LoggerInterface;
use Spatie\Robots\RobotsTxt;
use Symfony\Contracts\Service\ResetInterface;

final class RobotsTxtChecker implements RobotsTxtCheckerInterface, ResetInterface
{
    /** @var array<string, RobotsTxt|null> */
    private array $cache = [];

    public function __construct(
        private readonly RequestExecutorInterface $requestExecutor,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Forgets the cached robots.txt files, so every crawl run reads the
     * current version.
     */
    public function reset(): void
    {
        $this->cache = [];
    }

    /** @return array<int,string> */
    public function filterAllowed(array $urls, PipelineConfig $config): array
    {
        $robotsUrl = $config->robotsUrl();
        if (null == $robotsUrl || '' == $robotsUrl) {
            return array_values(array_unique($urls));
        }

        $robots = $this->getRobots($robotsUrl, $config);
        if (null === $robots) {
            return array_values(array_unique($urls));
        }

        $allowed = [];
        $ua = $config->userAgent();

        foreach ($urls as $url) {
            if ($robots->allows($url, $ua)) {
                $allowed[] = $url;
            }
        }

        return array_values(array_unique($allowed));
    }

    private function getRobots(string $robotsUrl, PipelineConfig $config): ?RobotsTxt
    {
        if (array_key_exists($robotsUrl, $this->cache)) {
            return $this->cache[$robotsUrl];
        }

        $robots = null;

        try {
            $response = $this->requestExecutor->request($robotsUrl, $config);

            if (null !== $response) {
                $content = $response->getContent(false);
                $robots = new RobotsTxt(trim($content));
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
