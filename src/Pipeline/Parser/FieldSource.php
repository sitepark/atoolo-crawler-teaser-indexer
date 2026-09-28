<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Parser;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Read-only access to the DOM of the single block currently being parsed.
 *
 * Field extractors receive this instead of the {@see Crawler} itself, and that
 * is the whole point of the class: a Crawler holds DOMNodes whose ownerDocument
 * is the *entire* page. An extractor that kept such a reference would keep that
 * page's parsed document alive - and extractors are container services that
 * live for the whole run, so a single stored reference leaks one document per
 * crawled page. The pipeline chunks its work precisely to bound that memory.
 *
 * This facade therefore never hands the Crawler out. The Parser creates one per
 * block and drops it when the block is done, so the parsed HTML stays as
 * short-lived as the chunking assumes.
 *
 * All accessors return null instead of throwing: a selector that does not match
 * is a normal outcome, and a malformed selector must not abort the block.
 */
final class FieldSource
{
    public function __construct(
        private readonly Crawler $crawler,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Text content of the first element matching the given CSS selector.
     */
    public function text(string $cssSelector): ?string
    {
        try {
            $element = $this->crawler->filter($cssSelector);
            if ($element->count() > 0) {
                return trim($element->first()->text());
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to parse CSS selector', [
                'selector' => $cssSelector,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Value of an attribute on the first element matching the given CSS
     * selector - e.g. the `datetime` of a `<time>` element.
     */
    public function attr(string $cssSelector, string $attr): ?string
    {
        try {
            $element = $this->crawler->filter($cssSelector);
            if ($element->count() > 0) {
                $value = $element->first()->attr($attr);

                return null !== $value ? trim($value) : null;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to parse CSS attr', [
                'selector' => $cssSelector,
                'attr' => $attr,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Content of a `<meta property="...">` tag - OpenGraph and friends.
     *
     * The property is compared in PHP rather than interpolated into the XPath
     * expression: it comes from the config or from project extractors, and a
     * quote in it would otherwise break or inject into the expression.
     */
    public function meta(string $property): ?string
    {
        try {
            foreach ($this->crawler->filterXPath('//meta[@property]') as $node) {
                if ($node instanceof \DOMElement && $node->getAttribute('property') === $property) {
                    return trim($node->getAttribute('content'));
                }
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
}
