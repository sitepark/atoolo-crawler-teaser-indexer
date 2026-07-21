<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Domain\Crawler\Services;

use Atoolo\CrawlerIndexer\Exception\StepExecution;
use Psr\Log\LoggerInterface;

class ExecuteStep
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Executes a single crawling step with logging and error handling.
     *
     * @param string   $name  The name of the step for logging purposes
     * @param callable $fn    The function representing the step
     * @param mixed    $input Optional input for the step function
     *
     * @return \Iterator<mixed>
     */
    public function executeStep(string $name, callable $fn, mixed $input = null): \Iterator
    {
        try {
            $result = $fn($input);

            if (is_array($result) && [] === $result) {
                $this->logger->warning("[$name] Step returned no data.");

                return new \ArrayIterator([]);
            }

            $this->logger->info("[$name] Step initialized.");

            if (is_array($result)) {
                return new \ArrayIterator($result);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("[$name] Error: " . $e->getMessage(), ['exception' => $e]);
            throw new StepExecution($name, $e->getMessage(), $e);
        }
    }
}
