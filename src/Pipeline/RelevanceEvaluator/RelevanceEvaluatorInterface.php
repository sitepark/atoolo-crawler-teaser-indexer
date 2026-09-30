<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Pipeline\Parser\FieldSource;

interface RelevanceEvaluatorInterface
{
    /**
     * Whether an extracted document is relevant enough to be indexed.
     *
     * @param ExtractedDataInterface $entry  the extracted fields (url, title, intro)
     * @param FieldSource            $source the already parsed block the entry came from - for
     *                                       reading the main content without parsing the HTML again
     */
    public function relevant(ExtractedDataInterface $entry, FieldSource $source, PipelineConfig $config): bool;
}
