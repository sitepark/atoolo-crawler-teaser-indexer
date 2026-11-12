<?php

namespace Atoolo\CrawlerIndexer\Messenger;

/**
 * Message used by the Symfony Scheduler and Messenger
 * to trigger a full crawl and index process.
 */
final class StartPipelineMessage
{
    /**
     * @param \DateTimeImmutable|null $scheduledAt the cron date the scheduler
     *        sent the message for; null for a message dispatched by hand,
     *        which always crawls
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $scheduledAt = null,
    ) {}
}
