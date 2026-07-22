<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Pipeline;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Pipeline\Collector\RobotsTxtChecker;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLCollector;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLNormalizer;
use Atoolo\CrawlerIndexer\Pipeline\Fetcher\Fetcher;
use Atoolo\CrawlerIndexer\Pipeline\Indexer\Indexer;
use Atoolo\CrawlerIndexer\Pipeline\Parser\Parser;
use Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator\RelevanceEvaluator;
use Atoolo\CrawlerIndexer\Pipeline\Processor\Processor;
use Atoolo\CrawlerIndexer\Ports\RequestExecutor;
use Atoolo\Search\Service\Indexer\IndexerProgressHandler;
use Atoolo\Search\Service\Indexer\SolrIndexService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Assembles a {@see CrawlerPipeline} for one run around an immutable
 * {@see PipelineConfig}.
 *
 * The config is a per-run value, so the config-dependent collaborators
 * (HTTP executor, fetcher, collector, parser, …) cannot be long-lived
 * container singletons. This factory holds the singleton infrastructure
 * (logger, HTTP client, Solr services, …) and wires a fresh graph per
 * site with the config injected via constructor - keeping call sites as
 * `$this->config->…()` without any shared mutable state.
 */
class CrawlerPipelineFactory
{
    /**
     * @param list<string> $denyEndings
     * @param array<int>   $retryStatusCodes
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly SolrIndexService $indexService,
        private readonly IndexerProgressHandler $progressHandler,
        private readonly array $denyEndings,
        private readonly array $retryStatusCodes,
    ) {}

    public function create(PipelineConfig $config): CrawlerPipeline
    {
        $requestExecutor = new RequestExecutor(
            $this->retryStatusCodes,
            $config,
            $this->httpClient,
            $this->logger,
        );
        $fetcher = new Fetcher($requestExecutor, $this->logger);

        $urlNormalizer = new URLNormalizer($config, $this->denyEndings);
        $robotsTxtChecker = new RobotsTxtChecker($config, $requestExecutor, $this->logger);
        $urlCollector = new URLCollector(
            $config,
            $urlNormalizer,
            $this->logger,
            $robotsTxtChecker,
            $fetcher,
        );

        $relevanceEvaluator = new RelevanceEvaluator($config);
        $parser = new Parser($this->logger, $config, $relevanceEvaluator);
        $processor = new Processor($this->logger, $config);
        $indexer = new Indexer($this->progressHandler, $this->indexService, $config, $this->logger);

        return new CrawlerPipeline(
            $urlCollector,
            $parser,
            $processor,
            $indexer,
            $this->logger,
        );
    }
}
