<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\AtooloCrawlerTeaserIndexerBundle;
use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Dto\ExtractedData;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\CrawlerIndexer\Pipeline\Parser\Parser;
use Atoolo\CrawlerIndexer\Pipeline\Parser\ParserInterface;
use Atoolo\Search\Service\Indexer\IndexerConfigurationLoader;
use Atoolo\Search\Service\Indexer\IndexerProgressHandler;
use Atoolo\Search\Service\Indexer\SolrIndexService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wires the bundle's real services.yaml and checks that the steps are
 * container services a project can decorate - including two stacked
 * decorators (commons project + customer project) that do not know about
 * each other.
 */
final class StepDecorationTest extends TestCase
{
    /**
     * @param list<class-string> $decorators
     */
    private function buildContainer(array $decorators = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('atoolo.crawler.schedule', []);
        $container->setParameter('atoolo.crawler.retry_status_codes', []);
        $container->setParameter('atoolo.crawler.deny_endings', []);

        (new AtooloCrawlerTeaserIndexerBundle())->build($container);

        // Services the host application provides.
        foreach (
            [
                LoggerInterface::class,
                HttpClientInterface::class,
                CacheInterface::class,
                'atoolo_search.indexer.solr_index_service',
                'atoolo_search.indexer.internal_resource_progress_state',
                'atoolo_search.indexer.configuration_loader',
            ] as $id
        ) {
            $container->register($id)->setSynthetic(true);
        }

        foreach ($decorators as $decorator) {
            $container->register($decorator, $decorator)->setAutowired(true)->setAutoconfigured(true);
        }

        $container->getAlias(ParserInterface::class)->setPublic(true);
        $container->getDefinition(CrawlerPipeline::class)->setPublic(true);

        $container->compile();

        $container->set(LoggerInterface::class, new NullLogger());
        $container->set(HttpClientInterface::class, new MockHttpClient());
        $container->set(CacheInterface::class, $this->createStub(CacheInterface::class));
        $container->set('atoolo_search.indexer.solr_index_service', $this->createStub(SolrIndexService::class));
        $container->set('atoolo_search.indexer.internal_resource_progress_state', $this->createStub(IndexerProgressHandler::class));
        $container->set('atoolo_search.indexer.configuration_loader', $this->createStub(IndexerConfigurationLoader::class));

        return $container;
    }

    private function config(): PipelineConfig
    {
        return new PipelineConfig(new PipelineConfigHelper(['sp_title_css' => ['h1']], new NullLogger()));
    }

    /**
     * @return list<string>
     */
    private function parseTitles(ParserInterface $parser): array
    {
        $page = [['url' => 'https://example.com/', 'html' => '<html><body><h1>Titel</h1></body></html>']];

        return array_map(
            static fn(ExtractedData $entry): string => $entry->getTitle(),
            iterator_to_array($parser->extractData($page, $this->config()), false),
        );
    }

    public function testAllStepsAreResolvableContainerServices(): void
    {
        $container = $this->buildContainer();

        $this->assertInstanceOf(CrawlerPipeline::class, $container->get(CrawlerPipeline::class));
        $this->assertInstanceOf(Parser::class, $container->get(ParserInterface::class));
    }

    public function testProjectDecoratorWrapsTheStep(): void
    {
        $parser = $this->buildContainer([CustomerParserDecorator::class])->get(ParserInterface::class);

        self::assertInstanceOf(ParserInterface::class, $parser);
        $this->assertInstanceOf(CustomerParserDecorator::class, $parser);
        $this->assertSame(['Titel [Kunde]'], $this->parseTitles($parser));
    }

    /**
     * Commons decorates with a higher priority (closer to the original step),
     * the customer project on top - neither has to know the other.
     */
    public function testCommonsAndCustomerDecoratorsStack(): void
    {
        $parser = $this->buildContainer([
            CustomerParserDecorator::class,
            CommonsParserDecorator::class,
        ])->get(ParserInterface::class);

        self::assertInstanceOf(ParserInterface::class, $parser);
        $this->assertSame(['Titel [Commons] [Kunde]'], $this->parseTitles($parser));
    }
}

/**
 * Appends a marker to every title, so the tests can see which decorators ran
 * and in which order.
 */
abstract class TitleMarkingParserDecorator implements ParserInterface
{
    public function __construct(
        private readonly ParserInterface $inner,
    ) {}

    abstract protected function marker(): string;

    public function extractData(array $htmlData, PipelineConfig $config): \Generator
    {
        foreach ($this->inner->extractData($htmlData, $config) as $entry) {
            yield new ExtractedData(
                $entry->getUrl(),
                $entry->getTitle() . ' ' . $this->marker(),
                $entry->getIntroText(),
                $entry->getDate(),
            );
        }
    }
}

#[AsDecorator(ParserInterface::class, priority: 10)]
final class CommonsParserDecorator extends TitleMarkingParserDecorator
{
    public function __construct(#[AutowireDecorated] ParserInterface $inner)
    {
        parent::__construct($inner);
    }

    protected function marker(): string
    {
        return '[Commons]';
    }
}

#[AsDecorator(ParserInterface::class, priority: 0)]
final class CustomerParserDecorator extends TitleMarkingParserDecorator
{
    public function __construct(#[AutowireDecorated] ParserInterface $inner)
    {
        parent::__construct($inner);
    }

    protected function marker(): string
    {
        return '[Kunde]';
    }
}
