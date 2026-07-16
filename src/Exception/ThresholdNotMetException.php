<?php

namespace Atoolo\Crawler\Exception;

class ThresholdNotMetException extends \Exception
{
    public function __construct(int $successCount, int $threshold)
    {
        parent::__construct(
            sprintf(
                'Process failed: Only %d successful imports, threshold is %d',
                $successCount,
                $threshold,
            ),
        );
    }
}
