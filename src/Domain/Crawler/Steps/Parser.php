<?php

namespace Atoolo\CrawlerIndexer\Domain\Crawler\Steps;

use Atoolo\CrawlerIndexer\Config\CrawlerConfig;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\DateTimeExtractConfig;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\IntroExtractConfig;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\TeaserData;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\TeaserDataInterface;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\TeaserRelevanceEvaluatorInterface;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\TitleExtractConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

class Parser
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CrawlerConfig $config,
        private readonly TeaserRelevanceEvaluatorInterface $teaserRelevanceEvaluator,
    ) {}

    /**
     * Extract teaser-data from fetched HTML.
     *
     * @param array<int, array{url: string, html: string}> $htmlData
     *
     * @return TeaserDataInterface[]
     */
    public function extractTeasers(array $htmlData): array
    {
        $results = [];

        $titleConfig = $this->config->titleConfig();
        $introConfig = $this->config->introTextConfig();
        $dateTimeConfig = $this->config->dateTimeConfig();

        $scoringActive = $this->config->contentScoringActive();

        foreach ($htmlData as $item) {
            $html = $item['html'];
            if (empty($html)) {
                continue;
            }

            try {
                if (strlen($html) > 2_000_000) {
                    $this->logger->warning('Skipping huge HTML', [
                        'url' => $item['url'],
                        'bytes' => strlen($html),
                    ]);
                    continue;
                }
                $crawler = new Crawler($html);

                $title = $this->extractTitleText($crawler, $titleConfig);
                if (null === $title || '' === $title) {
                    $this->logger->debug(
                        'Title Not found in Processor',
                        [
                            'key' => 'title',
                            'url' => $item['url'],
                            'dataFound' => $title
                        ],
                    );
                    continue;
                }

                $url = $item['url'];
                $teaserTitle = ($titleConfig->prefix ?? '') . $title;

                $introText = $this->extractIntroductionText($crawler, $introConfig);
                if (null === $introText && $introConfig->requiredField) {
                    continue;
                }

                $dateTime = $this->extractDateTime($crawler, $dateTimeConfig);
                if (null === $dateTime && $dateTimeConfig->requiredField) {
                    continue;
                }

                if ($scoringActive) {
                    $relevanceData = [
                        'url' => $url,
                        'title' => $teaserTitle,
                        'introText' => $introText,
                        'html' => $html,
                    ];
                    $keepTeaser = $this->teaserRelevanceEvaluator->relevant($relevanceData);
                    if (!$keepTeaser) {
                        $this->logger->debug(
                            'Teaser not Relevant',
                            ['relevanceData' => $relevanceData],
                        );
                        continue;
                    }
                }
                $results[] = new TeaserData($url, $teaserTitle, $introText, $dateTime);
            } catch (\Throwable $e) {
                $this->logger->warning('[Parser] No Data found for URL', [
                    'url' => $item['url'],
                    'exception' => $e,
                ]);
            }
        }

        return $results;
    }

    private function extractTitleText(Crawler $crawler, TitleExtractConfig $config): ?string
    {
        if (!$config->present) {
            return null;
        }

        // OG/Meta have priority
        foreach ($config->opengraph as $property) {
            $title = $this->findMetaTagContent($crawler, $property);
            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        // CSS Fallbacks
        foreach ($config->css as $selector) {
            $title = $this->findCssSelectorContent($crawler, $selector);
            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        $this->logger->debug(
            'Title Not found in Processor',
            ['key' => 'title', 'dataFound' => $title ?? ''],
        );

        return null;
    }

    private function extractIntroductionText(Crawler $crawler, IntroExtractConfig $config): ?string
    {
        if (!$config->present) {
            return null;
        }

        // OG/Meta have priority
        foreach ($config->opengraph as $property) {
            $introductionText = $this->findMetaTagContent($crawler, $property);
            if (null !== $introductionText && '' !== $introductionText) {
                return $introductionText;
            }
        }

        // CSS Fallbacks
        foreach ($config->css as $selector) {
            $introductionText = $this->findCssSelectorContent($crawler, $selector);
            if (null !== $introductionText && '' !== $introductionText) {
                return $introductionText;
            }
        }

        return null;
    }

    private function extractDateTime(Crawler $crawler, DateTimeExtractConfig $config): ?\DateTimeImmutable
    {
        if (!$config->present) {
            return null;
        }

        $raw = $this->findDateTimeRaw($crawler, $config);

        if (null === $raw) {
            return null;
        }

        $raw = $this->normalizeDateTimeRaw($raw, $config);

        $dt = $this->parseDateTime($raw);

        if (null === $dt && $config->requiredField) {
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
                $raw
                    = $this->findAttrByCss($crawler, $selector, 'datetime')
                    ?? $this->findCssSelectorContent($crawler, $selector);

                if (!empty($raw)) {
                    break;
                }
            }
        }

        $raw = is_string($raw) ? trim($raw) : '';

        return '' !== $raw ? $raw : null;
    }

    private function normalizeDateTimeRaw(string $raw, DateTimeExtractConfig $config): string
    {
        $raw = trim($raw);

        if ($config->onlyDate) {
            $date = \DateTime::createFromFormat('Y-m-d', $raw);

            if ($date && $date->format('Y-m-d') === $raw) {
                return $raw . ' 00:00:00';
            }
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

                return null !== $v ? trim((string) $v) : null;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to parse CSS attr', [
                'selector' => $selector,
                'attr' => $attr,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Extracts the text content of a meta tag by its property attribute.
     *
     * @param Crawler $crawler  The DomCrawler instance containing the HTML document
     * @param string  $property The meat-tag property
     *
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
            $this->logger->error('Failed to parse meta tag', [
                'property' => $property,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Extracts the text content of the first element matching a given CSS selector.
     *
     * @param Crawler $crawler  The DomCrawler instance containing the HTML document
     * @param string  $selector The CSS selector
     *
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
            $this->logger->error('Failed to parse CSS selector', [
                'selector' => $selector,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
