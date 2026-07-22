<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Indexer;

use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\Search\Dto\Indexer\IndexerStatus;

interface IndexerInterface
{
    /**
     * Marks the start of a crawl run so the reported duration covers the
     * whole process (crawling + parsing + indexing), not just the indexing
     * step.
     */
    public function prepare(string $message): void;

    /**
     * Enriches and commits the processed teaser documents to the index.
     *
     * @param ExtractedDataInterface[] $finalDocuments
     */
    public function doIndex(array $finalDocuments): IndexerStatus;
}
