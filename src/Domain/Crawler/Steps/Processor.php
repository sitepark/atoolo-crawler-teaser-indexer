<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Domain\Crawler\Steps;

use Atoolo\CrawlerIndexer\Config\CrawlerConfig;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\ExtractedData;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\ExtractedDataInterface;
use Psr\Log\LoggerInterface;

/**
 * The Processor is responsible for sanitizing and normalizing document.
 * It removes potentially unsafe or irrelevant elements (e.g., HTML tags,
 * scripts, styles), decodes entities, trims whitespace, and ensures titles
 * are consistently formatted. If titles exceed the defined maximum length,
 * they are truncated to maintain uniformity.
 *
 * By encapsulating these cleaning and formatting rules, the Processor
 * guarantees that all downstream components receive safe, consistent,
 * and usable data. Within the pipeline, it acts as the "data preparation"
 * stage, transforming raw extracted content into standardized document
 * information.
 */
class Processor
{
    public function __construct(
        private LoggerInterface $logger,
        private readonly CrawlerConfig $config,
    ) {}

    /**
     * @param iterable<int, ExtractedDataInterface> $rawextractedData
     *
     * @return \Generator<int, ExtractedDataInterface>
     */
    public function sanitizeText(iterable $rawextractedData): iterable
    {
        foreach ($rawextractedData as $item) {
            try {
                $cleanTitle = $this->cleanString($item->getTitle());
                $titleConfig = $this->config->titleConfig();
                $truncatedTitle = $this->truncate($cleanTitle, $titleConfig->maxChars);

                if ('' === $truncatedTitle) {
                    continue;
                }

                $cleanIntroText = null;
                $rawIntroText = $item->getIntroText();
                if (null !== $rawIntroText && '' !== $rawIntroText) {
                    $introTextConfig = $this->config->introTextConfig();
                    $cleanIntroText = $this->truncate($this->cleanString($rawIntroText), $introTextConfig->maxChars);
                }

                yield new ExtractedData(
                    $item->getUrl(),
                    $truncatedTitle,
                    $cleanIntroText,
                    $item->getDate(),
                );
            } catch (\Throwable $e) {
                $this->logger->error('[Processor] Failed to process document', [
                    'exception' => $e->getMessage(),
                    'item' => $item,
                ]);
            }
        }
    }

    /**
     * Strips HTML, scripts, styles, and normalizes whitespace in a string.
     *
     * @param string $text the raw text input
     *
     * @return string the cleaned text
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
     * Truncates a document string to a maximum length of 120 characters.
     *
     * @param string $text the cleaned text
     *
     * @return string the truncated text with Ellipsis "…" appended if cut
     */
    private function truncate(string $text, int $maxLength): string
    {
        if ('' === $text) {
            $this->logger->warning('[Processor] Empty document text encountered');

            return '';
        }

        return mb_strlen($text) > $maxLength
            ? mb_substr($text, 0, $maxLength) . '…'
            : $text;
    }
}
