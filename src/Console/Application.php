<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

final class Application extends BaseApplication
{
    public function __construct(CommandLoaderInterface $commandLoader)
    {
        parent::__construct();
        $this->setCommandLoader($commandLoader);
    }
}
