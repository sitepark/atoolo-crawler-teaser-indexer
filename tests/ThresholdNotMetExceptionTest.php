<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Exception\ThresholdNotMetException;
use PHPUnit\Framework\TestCase;

final class ThresholdNotMetExceptionTest extends TestCase
{
    public function testExceptionMessageContainsCountsAndThreshold(): void
    {
        $exception = new ThresholdNotMetException(3, 10);

        $this->assertStringContainsString('3', $exception->getMessage());
        $this->assertStringContainsString('10', $exception->getMessage());
    }

    public function testExceptionIsInstanceOfException(): void
    {
        $exception = new ThresholdNotMetException(0, 5);

        $this->assertInstanceOf(\Exception::class, $exception);
    }
}
