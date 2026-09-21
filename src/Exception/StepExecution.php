<?php

namespace Atoolo\Crawler\Exception;

class StepExecution extends \Exception
{
    public function __construct(string $stepName, string $message, \Throwable $previous)
    {
        parent::__construct(
            "Step [$stepName] failed: $message",
            0,
            $previous,
        );
    }
}
