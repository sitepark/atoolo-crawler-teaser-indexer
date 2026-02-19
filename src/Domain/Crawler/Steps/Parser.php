<?php

namespace Atoolo\Crawler\Domain\Crawler\Steps;

use Atoolo\Crawler\Config\CrawlerConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Atoolo\Crawler\Domain\Crawler\Services\FieldExtractConfig;
use Atoolo\Crawler\Domain\Crawler\Services\DateTimeExtractConfig;
use Atoolo\Crawler\Domain\Crawler\Services\TeaserRelevanceEvaluatorInterface;

class Parser
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CrawlerConfig $config,
        private readonly TeaserRelevanceEvaluatorInterface $teaserRelevanceEvaluator,
    ) {
    }

    /**
     * Extract teaser-data from fetched HTML.
     *
     * @param array<int, array{url: string, html: string}> $htmlData
     * @return array<int, array{url: string, title: string, introText?: string, datetime?: \DateTimeImmutable}>
     */
    public function extractTeasers(array $htmlData): array
    {
        $results = [];

        $titleConfig = $this->config->titleConfig();
        $introConfig = $this->config->introTextConfig();
        $dateTimeConfig = $this->config->dateTimeConfig();

        $scoringActive = $this->config->contentScoringActive();

        foreach ($htmlData as $item) {
            if (empty($item['html'])) {
                continue;
            }

            try {
                $html = $item['html'];

                if (strlen($html) > 2_000_000) {
                    $this->logger->warning('Skipping huge HTML', [
                        'url' => $item['url'],
                        'bytes' => strlen($html),
                    ]);
                    continue;
                }
                $crawler = new Crawler($html);

                $title = $this->extractText($crawler, $titleConfig);
                if ($title === null || $title === '') {
                    continue;
                }

                $teaserData = [
                    'url'   => $item['url'],
                    'title' => ($titleConfig->prefix ?? '') . $title,
                ];

                $introText = $this->extractText($crawler, $introConfig);
                if ($introText !== null) {
                    $teaserData['introText'] = $introText;
                } else {
                    if ($introConfig->present) {
                        continue;
                    }
                }

                $dateTime = $this->extractDateTime($crawler, $dateTimeConfig);
                if ($dateTime !== null) {
                    $teaserData['datetime'] = $dateTime;
                } else {
                    if ($dateTimeConfig->present) {
                        continue;
                    }
                }


                if ($scoringActive) {
                    $relevanceData = $teaserData;
                    $relevanceData["html"] = $html;
                    $keepTeaser = $this->teaserRelevanceEvaluator->relevant($relevanceData);
                    if (!$keepTeaser) {
                        continue;
                    }
                }
                $results[] = $teaserData;
            } catch (\Throwable $e) {
                $this->logger->warning('[Parser] No Data found for URL', [
                    'url'       => $item['url'],
                    'exception' => $e,
                ]);
            }
        }
        return $results;
    }

    private function extractText(Crawler $crawler, FieldExtractConfig $config): ?string
    {
        if (!$config->present) {
            return null;
        }

        // OG/Meta have priority
        foreach ($config->opengraph as $property) {
            $v = $this->findMetaTagContent($crawler, $property);
            if ($v !== null && $v !== '') {
                return $this->truncate($v, $config->maxChars);
            }
        }

        // CSS Fallbacks
        foreach ($config->css as $selector) {
            $v = $this->findCssSelectorContent($crawler, $selector);
            if ($v !== null && $v !== '') {
                return $this->truncate($v, $config->maxChars);
            }
        }

        return null;
    }

    private function truncate(string $text, int $maxChars): string
    {
        $text = trim($text);
        if ($maxChars <= 0) {
            return $text;
        }

        // mb_* für UTF-8
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $cut = rtrim(mb_substr($text, 0, max(0, $maxChars - 3)));
        return $cut . '...';
    }

    private function extractDateTime(Crawler $crawler, DateTimeExtractConfig $config): ?\DateTimeImmutable
    {
        if (!$config->present) {
            return null;
        }

        $raw = $this->findDateTimeRaw($crawler, $config);

        if ($raw === null) {
            return null; // requiredField wird ggf. außerhalb (skip) behandelt
        }

        $raw = $this->normalizeDateTimeRaw($raw, $config);

        $dt = $this->parseDateTime($raw);

        if ($dt === null && $config->requiredField) {
            return null;
        }

        return $dt;
    }

    private function findDateTimeRaw(Crawler $crawler, DateTimeExtractConfig $config): ?string
    {
        $raw = null;

        foreach ($config->opengraph as $property) {
            $raw = $this->findMetaTagContent($crawler, $property);
            if (!empty($raw)) {
                break;
            }
        }

        if (empty($raw)) {
            foreach ($config->css as $selector) {
                $raw =
                    $this->findAttrByCss($crawler, $selector, 'datetime')
                    ?? $this->findCssSelectorContent($crawler, $selector);

                if (!empty($raw)) {
                    break;
                }
            }
        }

        $raw = is_string($raw) ? trim($raw) : '';

        return $raw !== '' ? $raw : null;
    }

    private function normalizeDateTimeRaw(string $raw, DateTimeExtractConfig $config): string
    {
        $raw = trim($raw);

        if (
            $config->onlyDate
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1
        ) {
            $raw .= ' 00:00:00';
        }

        return $raw;
    }

    private function parseDateTime(string $raw): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            $this->logger->warning('[Parser] Could not parse datetime', [
                'raw' => $raw,
                'exception' => $e,
            ]);
            return null;
        }
    }


    private function findAttrByCss(Crawler $crawler, string $selector, string $attr): ?string
    {
        try {
            $el = $crawler->filter($selector);
            if ($el->count() > 0) {
                $v = $el->first()->attr($attr);
                return $v !== null ? trim((string)$v) : null;
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to parse CSS attr", [
                'selector' => $selector,
                'attr' => $attr,
                'exception' => $e
            ]);
            return null;
        }
    }

    /**
     * Extracts the text content of a meta tag by its property attribute.
     * @param Crawler $crawler  The DomCrawler instance containing the HTML document
     * @param string  $property The meat-tag property
     * @return string|null The text content, or `null` if not found or on error
     */
    private function findMetaTagContent(Crawler $crawler, string $property): ?string
    {
        try {
            $metaTag = $crawler->filterXPath("//meta[@property='$property']");
            if ($metaTag->count() > 0) {
                return trim((string) $metaTag->attr('content'));
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to parse meta tag", [
                'property'  => $property,
                'exception' => $e
            ]);
            return null;
        }
    }
    /**
     * Extracts the text content of the first element matching a given CSS selector
     * @param Crawler $crawler  The DomCrawler instance containing the HTML document
     * @param string  $selector The CSS selector
     * @return string|null The text content, or `null` if not found or on error
     */
    private function findCssSelectorContent(Crawler $crawler, string $selector): ?string
    {
        try {
            $element = $crawler->filter($selector);
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->error("Failed to parse CSS selector", [
                'selector'  => $selector,
                'exception' => $e
            ]);
            return null;
        }
    }
}
