<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Pipeline\Processor;

use Atoolo\Crawler\Proposal\Config\PipelineConfig;
use Atoolo\Crawler\Proposal\Dto\IndexEntry;
use Atoolo\Crawler\Proposal\Pipeline\ProcessorStepInterface;

final class ProcessorStep implements ProcessorStepInterface
{
    /**
     * @param iterable<IndexEntry> $items
     *
     * @return iterable<IndexEntry>
     */
    public function process(iterable $items, PipelineConfig $config): iterable
    {
        foreach ($items as $item) {
            $title = $this->clean($item->title);
            if ('' === $title) {
                continue;
            }

            yield $item
                ->withTitle($title)
                ->withIntroText(
                    null !== $item->introText && '' !== $item->introText
                        ? $this->clean($item->introText)
                        : null,
                );
        }
    }

    private function clean(string $text): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
