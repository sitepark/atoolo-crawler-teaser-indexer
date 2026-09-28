<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Exception\IndexingErrorsException;
use PHPUnit\Framework\TestCase;

final class IndexingErrorsExceptionTest extends TestCase
{
    public function testMessageContainsErrorCountAndStatusLine(): void
    {
        $exception = new IndexingErrorsException(3, '[FINISHED] processed: 7/10, errors: 3');

        $this->assertSame(
            'Indexing finished with 3 error(s): [FINISHED] processed: 7/10, errors: 3',
            $exception->getMessage(),
        );
        $this->assertSame(3, $exception->errors);
    }
}
