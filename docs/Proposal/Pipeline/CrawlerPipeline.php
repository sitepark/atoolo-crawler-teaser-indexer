<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Proposal\Pipeline;

use Atoolo\CrawlerIndexer\Proposal\Config\PipelineConfig;
use Psr\Log\LoggerInterface;

/** Orchestrates the lazy step chain. Replaces Controller\CrawlerPipeline. */
final class CrawlerPipeline
{
    public function __construct(
        private readonly CrawlerStepInterface $crawlerStep,
        private readonly ParserStepInterface $parserStep,
        private readonly ProcessorStepInterface $processorStep,
        private readonly IndexerStepInterface $indexerStep,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(PipelineConfig $config): void
    {
        $pages     = $this->crawlerStep->crawl($config);
        $entries   = $this->parserStep->parse($pages, $config);
        $processed = $this->processorStep->process($entries, $config);

        try {
            $this->indexerStep->index($processed, $config);
            $this->logger->info('[Pipeline] Completed successfully.');
        } catch (\Throwable $e) {
            $this->logger->error('[Pipeline] Failed.', ['exception' => $e]);
        }
    }
}
