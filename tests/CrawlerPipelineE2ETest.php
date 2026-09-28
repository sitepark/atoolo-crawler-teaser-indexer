<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Pipeline\Collector\LinkFilter;
use Atoolo\CrawlerIndexer\Pipeline\Collector\RobotsTxtChecker;
use Atoolo\CrawlerIndexer\Pipeline\Collector\UrlCanonicalizer;
use Atoolo\CrawlerIndexer\Pipeline\Collector\URLCollector;
use Atoolo\CrawlerIndexer\Pipeline\CrawlerPipeline;
use Atoolo\CrawlerIndexer\Pipeline\Fetcher\Fetcher;
use Atoolo\CrawlerIndexer\Pipeline\Indexer\Indexer;
use Atoolo\CrawlerIndexer\Pipeline\Parser\Parser;
use Atoolo\CrawlerIndexer\Pipeline\Processor\Processor;
use Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator\RelevanceEvaluator;
use Atoolo\CrawlerIndexer\Ports\RequestExecutor;
use Atoolo\Search\Dto\Indexer\IndexerStatus;
use Atoolo\Search\Service\Indexer\IndexerProgressHandler;
use Atoolo\Search\Service\Indexer\SolrIndexService;
use Atoolo\Search\Service\Indexer\SolrIndexUpdater;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Solarium\QueryType\Update\Query\Document;
use Solarium\QueryType\Update\Result as SolrUpdateResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Runs the whole pipeline with the real steps against a fake website served
 * by MockHttpClient. Only Solr is stubbed; the documents handed to it are
 * checked.
 *
 * The fake site covers: link discovery (depth 0 → start page + linked pages),
 * allow/deny prefixes, deny endings, robots.txt, a 404, a 503 that succeeds on
 * retry, a differently spelled duplicate link, OpenGraph/CSS extraction,
 * truncation and a 1:N overview page.
 */
final class CrawlerPipelineE2ETest extends TestCase
{
    private const BASE = 'https://example.com';

    /** @var list<string> */
    private array $requestedUrls = [];

    /** @var list<Document> */
    private array $indexedDocuments = [];

    private int $flakyCalls = 0;

    /**
     * @return array<string, array{int, string}> path => [status, body]
     */
    private function site(): array
    {
        return [
            '/robots.txt' => [200, "User-agent: *\nDisallow: /blocked"],
            '/' => [200, '<html><body><h1>Startseite</h1><div id="content">'
                . '<a href="/news/a">A</a>'
                . '<a href="https://EXAMPLE.com:443/news/a">A, other spelling</a>'
                . '<a href="/news/b">B</a>'
                . '<a href="/intern/x">denied prefix</a>'
                . '<a href="/file.pdf">denied ending</a>'
                . '<a href="/blocked">robots.txt</a>'
                . '<a href="https://other.com/ext">not allowed</a>'
                . '<a href="/missing">404</a>'
                . '<a href="/flaky">503, then 200</a>'
                . '<a href="/overview">1:N</a>'
                . '</div></body></html>'],
            '/news/a' => [200, '<html><head><meta property="og:title" content="Artikel A"></head><body>'
                . '<h1>Wird von og:title überstimmt</h1>'
                . '<p class="intro"> Einleitung <b>A</b> </p>'
                . '<time datetime="2026-01-14">14. Januar</time>'
                . '</body></html>'],
            '/news/b' => [200, '<html><body><h1>Ein sehr langer Titel für Artikel B</h1></body></html>'],
            '/overview' => [200, '<html><body>'
                . '<article><h2>Teaser 1</h2></article>'
                . '<article><h2>Teaser 2</h2></article>'
                . '</body></html>'],
            '/flaky' => [200, '<html><body><h1>Flaky</h1></body></html>'],
            '/intern/x' => [200, '<html><body><h1>Intern</h1></body></html>'],
            '/file.pdf' => [200, '%PDF'],
            '/blocked' => [200, '<html><body><h1>Blocked</h1></body></html>'],
        ];
    }

    private function httpClient(): MockHttpClient
    {
        $site = $this->site();

        return new MockHttpClient(function (string $method, string $url) use ($site): MockResponse {
            $this->requestedUrls[] = $url;
            $path = parse_url($url, PHP_URL_PATH) ?: '/';

            if ('/flaky' === $path && 1 === ++$this->flakyCalls) {
                return new MockResponse('', ['http_code' => 503]);
            }

            if (!str_starts_with($url, self::BASE) || !isset($site[$path])) {
                return new MockResponse('', ['http_code' => 404]);
            }

            [$status, $body] = $site[$path];

            return new MockResponse($body, ['http_code' => $status]);
        });
    }

    private function config(): PipelineConfig
    {
        return new PipelineConfig(new PipelineConfigHelper([
            'sp_id' => 'e2e',
            'sp_start_urls' => [['sp_url' => self::BASE . '/', 'sp_extraction_depth' => 0]],
            'sp_allow_prefixes' => [self::BASE . '/'],
            'sp_deny_prefixes' => [self::BASE . '/intern'],
            'sp_deny_endings' => ['.pdf'],
            'sp_respect_robots_txt' => true,
            'sp_robots_url' => self::BASE . '/robots.txt',
            'sp_parallel_requests' => 2,
            'sp_max_retry' => 3,
            'sp_backoff_ms' => 0,
            'sp_delay_ms' => 0,
            'sp_split_html_document' => ['//article'],
            'sp_title_opengraph' => ['og:title'],
            'sp_title_css' => ['h1', 'h2'],
            'sp_title_max_chars' => 20,
            'sp_introText_present' => true,
            'sp_introText_css' => ['.intro'],
            'sp_introText_max_chars' => 200,
            'sp_datetime_present' => true,
            'sp_datetime_css' => ['time'],
            'sp_cleanup_threshold' => 0,
        ], new NullLogger()));
    }

    private function solrIndexService(): SolrIndexService
    {
        $result = $this->createStub(SolrUpdateResult::class);
        $result->method('getStatus')->willReturn(0);

        $updater = $this->createStub(SolrIndexUpdater::class);
        $updater->method('createDocument')->willReturnCallback(static fn(): Document => new Document());
        $updater->method('addDocument')->willReturnCallback(function (Document $document): void {
            $this->indexedDocuments[] = $document;
        });
        $updater->method('update')->willReturn($result);

        $indexService = $this->createMock(SolrIndexService::class);
        $indexService->method('updater')->willReturn($updater);
        $indexService->expects($this->once())->method('deleteExcludingProcessId');
        $indexService->expects($this->once())->method('commit');

        return $indexService;
    }

    private function pipeline(): CrawlerPipeline
    {
        $logger = new NullLogger();
        $requestExecutor = new RequestExecutor([503], $this->httpClient(), $logger);

        $progressHandler = $this->createStub(IndexerProgressHandler::class);
        $progressHandler->method('getStatus')->willReturn(IndexerStatus::empty());

        return new CrawlerPipeline(
            new URLCollector(
                new UrlCanonicalizer(),
                new LinkFilter(new RobotsTxtChecker($requestExecutor, $logger), []),
                $logger,
                new Fetcher($requestExecutor, $logger),
            ),
            new Parser($logger, new RelevanceEvaluator()),
            new Processor($logger),
            new Indexer($progressHandler, $this->solrIndexService(), $logger),
            $logger,
        );
    }

    /**
     * @return list<string>
     */
    private function indexedTitles(): array
    {
        return array_map(
            static fn(Document $document): string => (string) $document->getFields()['title'],
            $this->indexedDocuments,
        );
    }

    private function indexedDocument(string $url): Document
    {
        foreach ($this->indexedDocuments as $document) {
            if ($url === $document->getFields()['url']) {
                return $document;
            }
        }

        self::fail('No document indexed for ' . $url);
    }

    public function testCrawlsTheSiteAndIndexesTheExpectedDocuments(): void
    {
        $this->pipeline()->run($this->config());

        $this->assertSame(
            [
                'Startseite',
                'Artikel A',
                'Ein sehr langer Tit…',  // sp_title_max_chars 20, ellipsis included
                'Flaky',                 // 503 on the first attempt, 200 on the retry
                'Teaser 1',              // overview split into one document per <article>
                'Teaser 2',
            ],
            $this->indexedTitles(),
        );
    }

    public function testFetchesOnlyAllowedPagesAndEachOnlyOnce(): void
    {
        $this->pipeline()->run($this->config());

        $this->assertSame(
            [
                self::BASE . '/',
                self::BASE . '/robots.txt',
                self::BASE . '/news/a',
                self::BASE . '/news/b',
                self::BASE . '/missing',
                self::BASE . '/flaky',
                self::BASE . '/flaky',     // retry
                self::BASE . '/overview',
            ],
            $this->requestedUrls,
            'deny prefix, deny ending, robots.txt and foreign host are never requested; '
            . 'the second spelling of /news/a is not fetched again',
        );
    }

    public function testExtractsAndCleansFieldsOfAnArticle(): void
    {
        $this->pipeline()->run($this->config());

        $fields = $this->indexedDocument(self::BASE . '/news/a')->getFields();

        $this->assertSame('Artikel A', $fields['title']);
        $this->assertSame('Einleitung A', $fields['sp_intro']);
        self::assertInstanceOf(\DateTimeInterface::class, $fields['sp_date']);
        $this->assertSame('2026-01-14', $fields['sp_date']->format('Y-m-d'));
        $this->assertSame(['e2e'], $fields['sp_source']);
    }

    public function testDocumentsOfOneOverviewPageGetDistinctIds(): void
    {
        $this->pipeline()->run($this->config());

        $overviewIds = [];
        foreach ($this->indexedDocuments as $document) {
            $fields = $document->getFields();
            if (self::BASE . '/overview' === $fields['url']) {
                $overviewIds[] = $fields['id'];
            }
        }

        $this->assertCount(2, $overviewIds);
        $this->assertCount(2, array_unique($overviewIds));
    }
}
