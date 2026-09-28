<?php

namespace Atoolo\CrawlerIndexer\Exception;

/**
 * Raised when the indexer finished but reported errors for some documents.
 *
 * The run itself completed (the successful documents are committed), but the
 * site must not count as successfully crawled - otherwise the errors would
 * only show up in the log and the command would still exit with success.
 */
class IndexingErrorsException extends \Exception
{
    public function __construct(
        public readonly int $errors,
        string $statusLine,
    ) {
        parent::__construct(
            sprintf('Indexing finished with %d error(s): %s', $errors, $statusLine),
        );
    }
}
