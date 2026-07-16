<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Proposal\Config;

final class LengthConditionConfig
{
    public function __construct(
        public readonly ?int $bodyTextLengthLt = null,
    ) {
    }
}
