<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Indexer;

use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\Search\Dto\Indexer\IndexerStatus;

interface IndexerInterface
{
    /**
     * Enriches and commits the processed teaser documents to the index.
     *
     * @param ExtractedDataInterface[] $finalDocuments
     */
    public function doIndex(array $finalDocuments): IndexerStatus;
}
