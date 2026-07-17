<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Pipeline\Parser;

use Atoolo\Crawler\Proposal\Config\DateTimeExtractConfig;
use Atoolo\Crawler\Proposal\Config\FieldExtractConfig;
use Atoolo\Crawler\Proposal\Config\PipelineConfig;
use Atoolo\Crawler\Proposal\Dto\CrawledPage;
use Atoolo\Crawler\Proposal\Dto\IndexEntry;
use Atoolo\Crawler\Proposal\Pipeline\ParserStepInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

final class ParserStep implements ParserStepInterface
{
    public function __construct(
        private readonly RelevanceEvaluatorInterface $relevanceEvaluator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param iterable<CrawledPage> $pages
     *
     * @return iterable<IndexEntry>
     */
    public function parse(iterable $pages, PipelineConfig $config): iterable
    {
        $titleConfig    = $config->titleConfig;
        $introConfig    = $config->introTextConfig;
        $dateTimeConfig = $config->dateTimeConfig;
        $scoringActive  = $config->contentScoringActive;

        foreach ($pages as $page) {
            if ('' === $page->html) {
                continue;
            }

            try {
                if (strlen($page->html) > 2_000_000) {
                    $this->logger->warning('[Parser] Skipping oversized HTML', ['url' => $page->url, 'bytes' => strlen($page->html)]);
                    continue;
                }

                $crawler = new Crawler($page->html);

                $title = $this->extractText($crawler, $titleConfig);
                if (null === $title || '' === $title) {
                    continue;
                }

                $introText = $this->extractText($crawler, $introConfig);
                if (null === $introText && $introConfig->present) {
                    continue;
                }

                $dateTime = $this->extractDateTime($crawler, $dateTimeConfig);
                if (null === $dateTime && $dateTimeConfig->present) {
                    continue;
                }

                $item = new IndexEntry(
                    url: $page->url,
                    title: ($titleConfig->prefix ?? '') . $title,
                    introText: $introText,
                    datetime: $dateTime,
                );

                if ($scoringActive) {
                    $bodyText = $crawler->filter('body')->count() > 0
                        ? $crawler->filter('body')->text()
                        : '';
                    if (!$this->relevanceEvaluator->relevant($item, $bodyText, $config->contentScoringConfig)) {
                        continue;
                    }
                }

                yield $item;
            } catch (\Throwable $e) {
                $this->logger->warning('[Parser] Failed to extract item', ['url' => $page->url, 'exception' => $e]);
            }
        }
    }

    private function extractText(Crawler $crawler, FieldExtractConfig $config): ?string
    {
        if (!$config->present) {
            return null;
        }

        foreach ($config->opengraph as $property) {
            $v = $this->metaContent($crawler, $property);
            if (null !== $v && '' !== $v) {
                return $this->truncate($v, $config->maxChars);
            }
        }

        foreach ($config->css as $selector) {
            $v = $this->cssText($crawler, $selector);
            if (null !== $v && '' !== $v) {
                return $this->truncate($v, $config->maxChars);
            }
        }

        return null;
    }

    private function extractDateTime(Crawler $crawler, DateTimeExtractConfig $config): ?\DateTimeImmutable
    {
        if (!$config->present) {
            return null;
        }

        $raw = null;

        foreach ($config->opengraph as $property) {
            $raw = $this->metaContent($crawler, $property);
            if (!empty($raw)) {
                break;
            }
        }

        if (empty($raw)) {
            foreach ($config->css as $selector) {
                $raw = $this->cssAttr($crawler, $selector, 'datetime')
                    ?? $this->cssText($crawler, $selector);
                if (!empty($raw)) {
                    break;
                }
            }
        }

        $raw = is_string($raw) ? trim($raw) : '';
        if ('' === $raw) {
            return null;
        }

        if ($config->onlyDate) {
            $date = \DateTime::createFromFormat('Y-m-d', $raw);
            if ($date && $date->format('Y-m-d') === $raw) {
                $raw .= ' 00:00:00';
            }
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            $this->logger->warning('[Parser] Could not parse datetime', ['raw' => $raw, 'exception' => $e]);

            return null;
        }
    }

    private function metaContent(Crawler $crawler, string $property): ?string
    {
        try {
            $node = $crawler->filterXPath("//meta[@property='$property']");

            return $node->count() > 0 ? trim((string) $node->attr('content')) : null;
        } catch (\Throwable $e) {
            $this->logger->error('[Parser] Failed to read meta tag', ['property' => $property, 'exception' => $e]);

            return null;
        }
    }

    private function cssText(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector);

            return $node->count() > 0 ? trim($node->first()->text()) : null;
        } catch (\Throwable $e) {
            $this->logger->error('[Parser] Failed to read CSS selector', ['selector' => $selector, 'exception' => $e]);

            return null;
        }
    }

    private function cssAttr(Crawler $crawler, string $selector, string $attr): ?string
    {
        try {
            $node = $crawler->filter($selector);
            if ($node->count() > 0) {
                $v = $node->first()->attr($attr);

                return null !== $v ? trim((string) $v) : null;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('[Parser] Failed to read CSS attr', ['selector' => $selector, 'attr' => $attr, 'exception' => $e]);

            return null;
        }
    }

    private function truncate(string $text, int $maxChars): string
    {
        $text = trim($text);
        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars - 3)) . '...';
    }
}
