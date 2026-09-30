<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Command;

use Atoolo\CrawlerIndexer\Application\SitesRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'crawler:scheduler-atoolo-crawler-teaser-indexer',
    description: 'Run crawler for all configured sites sequentially (same logic as production handler).',
)]
final class PipelineCommand extends Command
{
    public function __construct(
        private readonly SitesRunner $sitesRunner,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->sitesRunner->runAll()->isSuccessful()
                ? Command::SUCCESS
                : Command::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->critical('Fatal error in crawler command', [
                'exception' => $e,
            ]);

            return Command::FAILURE;
        }
    }
}
