<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Pipeline\Collector\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UrlCanonicalizerTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @param list<string>         $urls
     *
     * @return list<string>
     */
    private function canonicalize(array $urls, array $config = []): array
    {
        return (new UrlCanonicalizer())->canonicalize(
            $urls,
            new PipelineConfig(new PipelineConfigHelper($config, new NullLogger())),
        );
    }

    public function testKeepsAlreadyCanonicalUrl(): void
    {
        $this->assertSame(['https://example.com/page'], $this->canonicalize(['https://example.com/page']));
    }

    public function testRemovesDuplicatesAndPreservesOrder(): void
    {
        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b'],
            $this->canonicalize(['https://example.com/a', 'https://example.com/b', 'https://example.com/a']),
        );
    }

    public function testLowercasesSchemeAndHostButNotPath(): void
    {
        $this->assertSame(
            ['https://example.com/Intern/Page'],
            $this->canonicalize(['HTTPS://EXAMPLE.com/Intern/Page']),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function portProvider(): iterable
    {
        yield 'default https port' => ['https://example.com:443/page', 'https://example.com/page'];
        yield 'default http port' => ['http://example.com:80/page', 'http://example.com/page'];
        yield 'custom port kept' => ['https://example.com:8080/page', 'https://example.com:8080/page'];
        yield 'http port on https kept' => ['https://example.com:80/page', 'https://example.com:80/page'];
    }

    /**
     * @dataProvider portProvider
     */
    public function testRemovesOnlyDefaultPorts(string $url, string $expected): void
    {
        $this->assertSame([$expected], $this->canonicalize([$url]));
    }

    /**
     * Different spellings of the same page collapse into one URL.
     */
    public function testSpellingsOfTheSameUrlAreDeduplicated(): void
    {
        $this->assertSame(
            ['https://example.com/page'],
            $this->canonicalize(['https://example.com/page', 'https://EXAMPLE.com:443/page']),
        );
    }

    public function testKeepsFragmentAndQueryByDefault(): void
    {
        $this->assertSame(
            ['https://example.com/page?foo=bar#section'],
            $this->canonicalize(['https://example.com/page?foo=bar#section']),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparsableUrlProvider(): iterable
    {
        yield 'no url' => ['not-a-url'];
        yield 'no scheme' => ['//example.com/page'];
        yield 'unparsable' => ['//'];
    }

    /**
     * @dataProvider unparsableUrlProvider
     */
    public function testPassesUnparsableUrlsThroughUnchanged(string $url): void
    {
        $this->assertSame([$url], $this->canonicalize([$url], [
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['utm_source'],
        ]));
    }

    public function testStripQueryParamsInactiveKeepsParams(): void
    {
        $result = $this->canonicalize(['https://example.com/page?utm_source=google&id=1'], [
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => ['utm_source'],
        ]);

        $this->assertSame(['https://example.com/page?utm_source=google&id=1'], $result);
    }

    public function testStripQueryParamsRemovesAllConfiguredParams(): void
    {
        $result = $this->canonicalize(['https://example.com/page?utm_source=a&utm_medium=b&id=1'], [
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['utm_source', 'utm_medium'],
        ]);

        $this->assertSame(['https://example.com/page?id=1'], $result);
    }

    public function testStripQueryParamsDropsEmptyQuery(): void
    {
        $result = $this->canonicalize(['https://example.com/page?session=abc', 'https://example.com/page'], [
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['session'],
        ]);

        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testStripsFragmentOnlyForConfiguredPrefixes(): void
    {
        $result = $this->canonicalize(
            ['https://example.com/search#q=1', 'https://example.com/page#section'],
            ['sp_strip_fragments' => ['https://example.com/search']],
        );

        $this->assertSame(['https://example.com/search', 'https://example.com/page#section'], $result);
    }

    /**
     * The canonicalizer only rewrites; deciding what to follow is the
     * LinkFilter's job.
     */
    public function testNeverDropsUrls(): void
    {
        $urls = ['https://other.com/file.pdf', 'https://example.com/admin/secret'];

        $this->assertSame($urls, $this->canonicalize($urls, [
            'sp_allow_prefixes' => ['https://example.com'],
            'sp_deny_prefixes' => ['https://example.com/admin'],
            'sp_deny_endings' => ['.pdf'],
        ]));
    }
}
