<?php

declare(strict_types=1);

namespace Atoolo\Crawler\Command;

use Atoolo\Crawler\Application\CrawlSiteRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'crawler:index',
    description: 'Run crawler for all configured sites sequentially (same logic as production handler).',
)]
final class Index extends Command
{
    public function __construct(
        private readonly array $sites,
        private readonly CrawlSiteRunner $runner
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = Command::SUCCESS;
        if ($this->sites === []) {
            $output->writeln('<comment>No sites configured (atoolo.crawler.sites is empty).</comment>');
        } else {
            foreach ($this->sites as $site) {
                $siteKey = $site['siteKey'] ?? null;

                if (!is_string($siteKey) || $siteKey === '') {
                    $output->writeln('<error>Invalid site config: missing "siteKey".</error>');
                    $exitCode = Command::FAILURE;
                    break;
                }

                try {
                    $this->runner->run($siteKey);
                } catch (\Throwable $e) {
                    $output->writeln(sprintf(
                        '<error>Crawling failed for "%s": %s</error>',
                        $siteKey,
                        $e->getMessage()
                    ));
                    $exitCode = Command::FAILURE;
                    break;
                }
            }
        }

        return $exitCode;
    }
}
