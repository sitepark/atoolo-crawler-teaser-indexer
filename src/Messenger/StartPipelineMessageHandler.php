<?php

namespace Atoolo\CrawlerIndexer\Messenger;

use Atoolo\CrawlerIndexer\Application\SitesRunner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class StartPipelineMessageHandler
{
    private ?\DateTimeImmutable $lastCrawlStartedAt = null;

    public function __construct(
        private readonly SitesRunner $sitesRunner,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {}

    /**
     * After a downtime the stateful schedule sends one message per missed
     * cron date, one after the other. One crawl brings the index up to date,
     * so a message whose date was due before the last crawl started is
     * skipped: missed dates lead to exactly one crawl.
     *
     * Configuration load errors propagate on purpose, so Messenger can apply
     * its retry / failure transport handling.
     */
    public function __invoke(StartPipelineMessage $message): void
    {
        if (
            $message->scheduledAt !== null
            && $this->lastCrawlStartedAt !== null
            && $message->scheduledAt < $this->lastCrawlStartedAt
        ) {
            $this->logger->info(sprintf(
                'Crawler run due at %s skipped, already covered by the crawl started at %s',
                $message->scheduledAt->format(\DATE_ATOM),
                $this->lastCrawlStartedAt->format(\DATE_ATOM),
            ));

            return;
        }

        $this->lastCrawlStartedAt = $this->clock->now();
        $this->sitesRunner->runAll();
    }
}
