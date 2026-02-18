<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Domain\Crawler\Steps;

use Psr\Log\LoggerInterface;

/**
 * The Processor is responsible for sanitizing and normalizing teaser data.
 * It removes potentially unsafe or irrelevant elements (e.g., HTML tags,
 * scripts, styles), decodes entities, trims whitespace, and ensures titles
 * are consistently formatted. If titles exceed the defined maximum length,
 * they are truncated to maintain uniformity.
 *
 * By encapsulating these cleaning and formatting rules, the Processor
 * guarantees that all downstream components receive safe, consistent,
 * and usable data. Within the pipeline, it acts as the "data preparation"
 * stage, transforming raw extracted content into standardized teaser
 * information.
 */
class Processor
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }
    /**
     * Sanitizes and normalizes teaser data by cleaning and truncating text.
     * * @param iterable $rawTeaserData Ein Stream (Generator oder Array) von Teasern
     * @return iterable Ein Stream von bereinigten Teasern
     */
    public function sanitizeText(iterable $rawTeaserData): iterable
    {
        foreach ($rawTeaserData as $item) {
            try {
                if (!is_array($item) || !isset($item['title'], $item['url'])) {
                    $this->logger->warning('[Processor] Unexpected teaser format', [
                        'actualType' => gettype($item),
                        'rawItem'    => $item,
                    ]);
                    continue;
                }

                $cleanTitle = $this->cleanString((string) $item['title']);
                $truncatedTitle = $this->truncate($cleanTitle);

                if ($truncatedTitle === '') {
                    continue;
                }

                $cleaned = [
                    'url'   => (string) $item['url'],
                    'title' => $truncatedTitle,
                ];

                if (isset($item['introText']) && (string) $item['introText'] !== '') {
                    $cleanIntroText = $this->cleanString((string) $item['introText']);
                    $cleaned['introText'] = $this->truncate($cleanIntroText);
                }

                if (isset($item['datetime']) && (string) $item['datetime'] !== '') {
                    $cleaned['datetime'] = $this->cleanString((string) $item['datetime']);
                }

                yield $cleaned;
            } catch (\Throwable $e) {
                $this->logger->error('[Processor] Failed to process teaser', [
                    'exception' => $e,
                    'item'      => $item,
                ]);
            }
        }
    }

    /**
     * Strips HTML, scripts, styles, and normalizes whitespace in a string.
     *
     * @param string $text The raw text input.
     * @return string The cleaned text.
     */
    private function cleanString(string $text): string
    {
        // Removes <script> and <style> blocks including their content
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text) ?? $text;
        // Removes all remaining HTML tags
        $text = strip_tags($text);
        // Decodes HTML entities into normal characters (e.g., &amp; → &)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapses multiple whitespaces (tabs/newlines) into a single space
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        // Removes leading and trailing whitespaces
        return trim($text);
    }

    /**
     * Truncates a teaser string to a maximum length of 120 characters.
     *
     * @param string $text The cleaned text.
     * @return string The truncated text with "..." appended if cut.
     */
    private function truncate(string $text): string
    {
        $maxLength = 120;
        if (!is_string($text)) {
            error_log("[Processor] Non-string teaser ignored: " . json_encode($text));
        }
        return mb_strlen($text) > $maxLength
            ? mb_substr($text, 0, $maxLength) . '...'
            : $text;
    }
}
