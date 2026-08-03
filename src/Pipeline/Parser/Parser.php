<?php

namespace Atoolo\CrawlerIndexer\Pipeline\Parser;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\DateTimeExtractConfig;
use Atoolo\CrawlerIndexer\Config\IntroExtractConfig;
use Atoolo\CrawlerIndexer\Dto\ExtractedData;
use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Config\TitleExtractConfig;
use Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator\RelevanceEvaluatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

class Parser implements ParserInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PipelineConfig $config,
        private readonly RelevanceEvaluatorInterface $relevanceEvaluator,
    ) {}

    /**
     * Extract documents from fetched HTML.
     *
     * A page yields one document per matching block when the XPath
     * `sp_split_html_document` is configured (1:N, e.g. an overview
     * page with many blocks), otherwise the whole page is a single document
     * (1:1).
     *
     * @param array<int, array{url: string, html: string}> $htmlData
     *
     * @return \Generator<int, ExtractedDataInterface>
     */
    public function extractData(array $htmlData): \Generator
    {
        $splitSelector = $this->config->splitHtmlDocumentSelector();

        foreach ($htmlData as $item) {
            $html = $item['html'];
            if (empty($html)) {
                continue;
            }

            if (strlen($html) > 2_000_000) {
                $this->logger->warning('Skipping huge HTML', [
                    'url' => $item['url'],
                    'bytes' => strlen($html),
                ]);
                continue;
            }

            $crawler = new Crawler($html);

            // Each block is parsed independently: a missing title, a missing
            // required field, or a parse error skips only that block - the
            // remaining blocks of the page are still emitted.
            foreach ($this->resolveBlocks($crawler, $splitSelector) as $block) {
                try {
                    $extracted = $this->extractFromBlock(
                        $block,
                        $item['url'],
                        $html,
                    );
                    if (null !== $extracted) {
                        yield $extracted;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('[Parser] No Data found for URL', [
                        'url' => $item['url'],
                        'exception' => $e,
                    ]);
                }
            }
        }
    }

    /**
     * Resolves the blocks a page is split into. With a valid split selector that
     * matches, each matching element becomes its own block (1:N). Otherwise -
     * no selector, no match, or an invalid XPath - the whole page is a single
     * block (1:1).
     *
     * A broad ":has"-style selector (e.g. `//div[.//h2]`) also matches wrapper
     * containers, since those have the block headings as descendants too. To
     * avoid emitting a wrapper as a duplicate of its inner block, any matched
     * node that is an ancestor of another matched node is dropped - the
     * innermost match wins.
     *
     * @param list<string> $splitSelectors
     *
     * @return list<Crawler>
     */
    private function resolveBlocks(Crawler $crawler, ?array $splitSelectors): array
    {
        if (null === $splitSelectors || [] === $splitSelectors) {
            return [$crawler];
        }

        $nodes = [];
        foreach ($splitSelectors as $splitSelector) {
            try {
                foreach ($crawler->filterXPath($splitSelector) as $node) {
                    $nodes[] = $node;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Parser] Invalid split selector, using whole page', [
                    'selector' => $splitSelector,
                    'exception' => $e,
                ]);
            }
        }

        if ([] === $nodes) {
            return [$crawler];
        }

        $blocks = [];
        foreach ($nodes as $node) {
            if ($this->isAncestorOfAny($node, $nodes)) {
                continue;
            }
            $blocks[] = new Crawler($node);
        }

        return $blocks;
    }

    /**
     * Whether $node is an ancestor of any other node in $others (identity by
     * DOM node, not object reference).
     *
     * @param list<\DOMNode> $others
     */
    private function isAncestorOfAny(\DOMNode $node, array $others): bool
    {
        foreach ($others as $other) {
            if ($node->isSameNode($other)) {
                continue;
            }
            for ($parent = $other->parentNode; null !== $parent; $parent = $parent->parentNode) {
                if ($parent->isSameNode($node)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extracts a single document from one block (whole page or split element).
     * Returns null when the block is skipped (no title, required field missing,
     * or filtered out by content scoring).
     */
    private function extractFromBlock(
        Crawler $crawler,
        string $url,
        string $html,
    ): ?ExtractedDataInterface {
        $titleConfig = $this->config->titleConfig();
        $introConfig = $this->config->introTextConfig();
        $dateTimeConfig = $this->config->dateTimeConfig();
        $scoringActive = $this->config->contentScoringActive();

        $title = '';
        if ($titleConfig->present) {
            $title = $this->extractTitleText($crawler, $titleConfig);
            if (null === $title || '' === $title) {
                $this->logger->debug(
                    'Title Not found in Processor',
                    [
                        'key' => 'title',
                        'url' => $url,
                    ],
                );

                return null;
            }
            $title = ($titleConfig->prefix ?? '') . $title;
        }

        $introText = null;
        if ($introConfig->present) {
            $introText = $this->extractIntroductionText($crawler, $introConfig);
            if (null === $introText && $introConfig->requiredField) {
                return null;
            }
        }

        $dateTime = null;
        if ($dateTimeConfig->present) {
            $dateTime = $this->extractDateTime($crawler, $dateTimeConfig);
            if (null === $dateTime && $dateTimeConfig->requiredField) {
                return null;
            }
        }

        if ($scoringActive) {
            $relevanceData = [
                'url' => $url,
                'title' => $title,
                'introText' => $introText,
                'html' => $html,
            ];
            $keepDocument = $this->relevanceEvaluator->relevant($relevanceData);
            if (!$keepDocument) {
                $this->logger->debug(
                    'Document not Relevant',
                    ['relevanceData' => $relevanceData],
                );

                return null;
            }
        }

        return new ExtractedData($url, $title, $introText, $dateTime);
    }

    private function extractTitleText(Crawler $crawler, TitleExtractConfig $config): ?string
    {
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
