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
    /**
     * @param iterable<FieldExtractorInterface> $fieldExtractors Project-supplied
     *                                                           extractors, tagged via autoconfiguration and passed through by
     *                                                           CrawlerPipelineFactory. Asked before the built-in extraction.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly PipelineConfig $config,
        private readonly RelevanceEvaluatorInterface $relevanceEvaluator,
        private readonly iterable $fieldExtractors = [],
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
            foreach (
                $this->resolveBlocks(
                    $crawler,
                ) as $crawlerBlock
            ) {
                try {
                    $extracted = $this->extractFromBlock(
                        $crawlerBlock,
                        $item['url'],
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
     * @return list<Crawler>
     */
    private function resolveBlocks(Crawler $crawler): array
    {
        $splitSelectors = $this->config->splitHtmlDocumentSelector();
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
    ): ?ExtractedDataInterface {
        $titleConfig = $this->config->titleConfig();
        $introConfig = $this->config->introTextConfig();
        $dateTimeConfig = $this->config->dateTimeConfig();
        $scoringActive = $this->config->contentScoringActive();

        // Bounded to this block: extractors never see the Crawler, so no
        // reference to the page's parsed DOM can outlive the loop iteration.
        $source = new FieldSource($crawler, $this->logger);

        $title = '';
        if ($titleConfig->present) {
            $title = $this->extractString(FieldExtractorInterface::FIELD_TITLE, $source)
                ?? $this->extractTitleText($source, $titleConfig);
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
            $introText = $this->extractString(FieldExtractorInterface::FIELD_INTRO_TEXT, $source)
                ?? $this->extractIntroductionText($source, $introConfig);
            if (null === $introText && $introConfig->requiredField) {
                return null;
            }
        }

        $dateTime = null;
        if ($dateTimeConfig->present) {
            $dateTime = $this->extractCustomDateTime($source)
                ?? $this->extractDateTime($source, $dateTimeConfig);
            if (null === $dateTime && $dateTimeConfig->requiredField) {
                return null;
            }
        }

        if ($scoringActive) {
            $relevanceContentSelector = $this->config->relevanceContentSelector();
            $relevanceData = [
                'url' => $url,
                'title' => $title,
                'introText' => $introText,
                'html' => $relevanceContentSelector ? ($source->text($relevanceContentSelector) ?? $crawler->outerHtml()) : $crawler->outerHtml(),
            ];
            $keepDocument = $this->relevanceEvaluator->relevant($relevanceData);
            if (!$keepDocument) {
                $this->logger->debug(
                    'Document not Relevant',
                    [
                        'url' => $relevanceData['url'],
                        'title' => $relevanceData['title'],
                        'introText' => $relevanceData['introText'],
                    ],
                );

                return null;
            }
        }

        return new ExtractedData($url, $title, $introText, $dateTime);
    }

    /**
     * Asks the registered extractors for a field. The first non-null value
     * wins; null means fall through to the built-in extraction.
     *
     * An extractor that throws is logged and skipped rather than allowed to
     * abort the block: a broken project extractor should degrade one field, not
     * drop the document.
     */
    private function extractCustom(string $field, FieldSource $source): mixed
    {
        foreach ($this->fieldExtractors as $extractor) {
            if (!$extractor->supports($field)) {
                continue;
            }

            try {
                $value = $extractor->extract($field, $source, $this->config);
            } catch (\Throwable $e) {
                $this->logger->error('[Parser] Field extractor failed, falling back', [
                    'field' => $field,
                    'extractor' => $extractor::class,
                    'exception' => $e,
                ]);
                continue;
            }

            if (null !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A custom string field. A non-string or empty value is a contract
     * violation by the extractor, so it is logged and the built-in extraction
     * takes over.
     */
    private function extractString(string $field, FieldSource $source): ?string
    {
        $value = $this->extractCustom($field, $source);

        if (null === $value) {
            return null;
        }

        if (is_string($value) && '' !== trim($value)) {
            return trim($value);
        }

        $this->logger->error('[Parser] Field extractor returned unexpected value, ignoring', [
            'field' => $field,
            'expected' => 'non-empty string',
            'actual' => get_debug_type($value),
        ]);

        return null;
    }

    /**
     * A custom datetime field. Anything but a \DateTimeInterface is a contract
     * violation by the extractor, so it is logged and the built-in extraction
     * takes over.
     */
    private function extractCustomDateTime(FieldSource $source): ?\DateTimeImmutable
    {
        $value = $this->extractCustom(FieldExtractorInterface::FIELD_DATETIME, $source);

        if (null === $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        $this->logger->error('[Parser] Field extractor returned unexpected value, ignoring', [
            'field' => FieldExtractorInterface::FIELD_DATETIME,
            'expected' => \DateTimeInterface::class,
            'actual' => get_debug_type($value),
        ]);

        return null;
    }

    private function extractTitleText(FieldSource $source, TitleExtractConfig $config): ?string
    {
        // OG/Meta have priority
        foreach ($config->opengraph as $property) {
            $title = $source->meta($property);
            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        // CSS Fallbacks
        foreach ($config->css as $selector) {
            $title = $source->text($selector);
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

    private function extractIntroductionText(FieldSource $source, IntroExtractConfig $config): ?string
    {
        // OG/Meta have priority
        foreach ($config->opengraph as $property) {
            $introductionText = $source->meta($property);
            if (null !== $introductionText && '' !== $introductionText) {
                return $introductionText;
            }
        }

        // CSS Fallbacks
        foreach ($config->css as $selector) {
            $introductionText = $source->text($selector);
            if (null !== $introductionText && '' !== $introductionText) {
                return $introductionText;
            }
        }

        return null;
    }

    private function extractDateTime(FieldSource $source, DateTimeExtractConfig $config): ?\DateTimeImmutable
    {
        $raw = $this->findDateTimeRaw($source, $config);

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

    private function findDateTimeRaw(FieldSource $source, DateTimeExtractConfig $config): ?string
    {
        $raw = null;

        foreach ($config->opengraph as $property) {
            $raw = $source->meta($property);
            if (!empty($raw)) {
                break;
            }
        }

        if (empty($raw)) {
            foreach ($config->css as $selector) {
                $raw
                    = $source->attr($selector, 'datetime')
                    ?? $source->text($selector);

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
}
