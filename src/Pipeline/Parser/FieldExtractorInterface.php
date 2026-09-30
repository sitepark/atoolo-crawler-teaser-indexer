<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline\Parser;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;

/**
 * Seam for replacing the extraction of a single field, without reimplementing
 * the Parser.
 *
 * The built-in extraction is fixed to "OpenGraph first, then CSS". Decorating
 * the whole Parser is no alternative: a decorator only sees the emitted
 * {@see \Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface}, which carries no
 * HTML, and it could not tell which split block an entry came from without
 * redoing the block resolution. So the seam sits *inside* the Parser, on the
 * already parsed DOM.
 *
 * A project registers an extractor as a service; autoconfiguration tags it and
 * the Parser asks it before falling back to the built-in path. Splitting,
 * required-field handling, the title prefix and content scoring stay with the
 * Parser.
 *
 * Two constraints follow from how the pipeline is wired:
 *
 * - Extractors are container singletons that live for the whole run, while
 *   {@see PipelineConfig} is a per-site value. It is therefore passed per call
 *   and must not be stored on the extractor.
 * - Extractors get a {@see FieldSource}, not a Crawler, so no reference to a
 *   page's parsed DOM can escape. Do not hold on to the FieldSource either; it
 *   is only valid for the duration of the call.
 *
 * Because the indexer writes a fixed set of Solr fields, an extractor can only
 * *replace* the extraction of an existing field - it cannot introduce new ones.
 */
interface FieldExtractorInterface
{
    public const FIELD_TITLE = 'title';
    public const FIELD_INTRO_TEXT = 'introText';
    public const FIELD_DATETIME = 'datetime';

    /**
     * Whether this extractor handles the given field.
     *
     * $field is one of the FIELD_* constants; unknown names must return false
     * so later fields can be added without breaking existing extractors.
     */
    public function supports(string $field): bool;

    /**
     * The extracted value for $field, or null to fall through - to the next
     * extractor that supports the field, and finally to the built-in
     * extraction.
     *
     * Expected types: a string for FIELD_TITLE and FIELD_INTRO_TEXT, a
     * \DateTimeInterface for FIELD_DATETIME. Any other type is logged and
     * ignored, as is a thrown exception - in both cases the built-in extraction
     * takes over, so a broken extractor degrades the field instead of losing
     * the document.
     */
    public function extract(string $field, FieldSource $source, PipelineConfig $config): mixed;
}
