<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Parser;

use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;

interface ParserInterface
{
    /**
     * Extracts teaser documents from a chunk of fetched HTML pages.
     *
     * @param array<int, array{url: string, html: string}> $htmlData
     *
     * @return \Generator<int, ExtractedDataInterface[]>
     */
    public function extractData(array $htmlData): \Generator;
}
