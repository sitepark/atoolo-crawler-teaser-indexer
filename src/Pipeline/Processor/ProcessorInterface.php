<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Processor;

use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;

interface ProcessorInterface
{
    /**
     * Sanitizes and cleans the extracted teaser data.
     *
     * @param iterable<int, ExtractedDataInterface> $rawextractedData
     *
     * @return iterable<int, ExtractedDataInterface>
     */
    public function sanitizeText(iterable $rawextractedData): iterable;
}
