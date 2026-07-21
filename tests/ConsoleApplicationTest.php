<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\CrawlerIndexer\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

final class ConsoleApplicationTest extends TestCase
{
    public function testApplicationIsInstanceOfBaseApplication(): void
    {
        $loader = $this->createStub(CommandLoaderInterface::class);
        $app = new Application($loader);

        $this->assertInstanceOf(BaseApplication::class, $app);
    }
}
