<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Parser;

use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;

interface ParserInterface
{
    /**
     * Extracts documents from a chunk of fetched HTML pages.
     *
     * A single page may yield 0, 1 or many documents (1:N): with a document
     * selector configured, each matching block becomes its own document.
     *
     * @param array<int, array{url: string, html: string}> $htmlData
     *
     * @return \Generator<int, ExtractedDataInterface>
     */
    public function extractData(array $htmlData): \Generator;
}
