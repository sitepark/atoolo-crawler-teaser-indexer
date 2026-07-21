<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\CrawlerIndexer\Config\CrawlerConfig;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigContext;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigHelper;
use Atoolo\CrawlerIndexer\Domain\Crawler\Services\URLNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class URLNormalizerTest extends TestCase
{
    private function makeNormalizer(array $config): URLNormalizer
    {
        $logger = $this->createStub(LoggerInterface::class);
        $ctx = new CrawlerConfigContext($config);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $crawlerConfig = new CrawlerConfig($helper);
        $denyEndings = [
            '.jpg',
            '.jpeg',
            '.png',
            '.gif',
            '.svg',
            '.webp',
            '.ico',
            '.bmp',
            '.tiff',
        ];

        return new URLNormalizer($crawlerConfig, $denyEndings);
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => [],
            'sp_allow_prefixes' => [],
            'sp_deny_prefixes' => [],
            'sp_deny_endings' => [],
        ], $overrides);
    }

    public function testNormalizeReturnsCleanUrl(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig());
        $result = $normalizer->normalize(['https://example.com/page']);
        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testNormalizeRemovesDuplicates(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig());
        $result = $normalizer->normalize([
            'https://example.com/page',
            'https://example.com/page',
        ]);
        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testNormalizePreservesOrder(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig());
        $result = $normalizer->normalize([
            'https://example.com/a',
            'https://example.com/b',
        ]);
        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $result);
    }

    public function testSanitizeUrlWithPort(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig());
        $result = $normalizer->normalize(['https://example.com:8080/page']);
        $this->assertSame(['https://example.com:8080/page'], $result);
    }

    public function testSanitizeUrlWithFragment(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig());
        $result = $normalizer->normalize(['https://example.com/page#section']);
        $this->assertSame(['https://example.com/page#section'], $result);
    }

    public function testSanitizeUrlWithQueryString(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig());
        $result = $normalizer->normalize(['https://example.com/page?foo=bar']);
        $this->assertSame(['https://example.com/page?foo=bar'], $result);
    }

    public function testSanitizeInvalidUrlPassedThrough(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig());
        $result = $normalizer->normalize(['not-a-url']);
        $this->assertSame(['not-a-url'], $result);
    }

    public function testSanitizeUrlWithoutSchemePassedThrough(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig());
        $result = $normalizer->normalize(['//example.com/page']);
        $this->assertSame(['//example.com/page'], $result);
    }

    public function testStripQueryParamsInactiveKeepsParams(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_strip_query_params_active' => false,
            'sp_strip_query_params' => ['utm_source'],
        ]));
        $result = $normalizer->normalize(['https://example.com/page?utm_source=google&id=1']);
        $this->assertStringContainsString('utm_source', $result[0]);
    }

    public function testStripQueryParamsActiveRemovesConfiguredParam(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['utm_source'],
        ]));
        $result = $normalizer->normalize(['https://example.com/page?utm_source=google&id=1']);
        $this->assertCount(1, $result);
        $this->assertStringNotContainsString('utm_source', $result[0]);
        $this->assertStringContainsString('id=1', $result[0]);
    }

    public function testStripQueryParamsActiveRemovesAllConfiguredParams(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['utm_source', 'utm_medium'],
        ]));
        $result = $normalizer->normalize(['https://example.com/page?utm_source=a&utm_medium=b&id=1']);
        $this->assertStringNotContainsString('utm_source', $result[0]);
        $this->assertStringNotContainsString('utm_medium', $result[0]);
        $this->assertStringContainsString('id=1', $result[0]);
    }

    public function testStripQueryParamsWithEmptyQueryDoesNotBreak(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['utm_source'],
        ]));
        $result = $normalizer->normalize(['https://example.com/page']);
        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testAllowPrefixesEmptyAllowsAll(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_allow_prefixes' => [],
        ]));
        $result = $normalizer->normalize([
            'https://example.com/a',
            'https://other.com/b',
        ]);
        $this->assertCount(2, $result);
    }

    public function testAllowPrefixesFiltersNonMatching(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_allow_prefixes' => ['https://example.com'],
        ]));
        $result = $normalizer->normalize([
            'https://example.com/page',
            'https://other.com/page',
        ]);
        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testAllowPrefixesKeepsMatchingUrls(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_allow_prefixes' => ['https://example.com/allowed'],
        ]));
        $result = $normalizer->normalize([
            'https://example.com/allowed/page1',
            'https://example.com/denied/page2',
        ]);
        $this->assertSame(['https://example.com/allowed/page1'], $result);
    }

    public function testDenyPrefixesEmptyAllowsAll(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_deny_prefixes' => [],
        ]));
        $result = $normalizer->normalize([
            'https://example.com/a',
            'https://example.com/b',
        ]);
        $this->assertCount(2, $result);
    }

    public function testDenyPrefixesFiltersMatchingUrls(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_deny_prefixes' => ['https://example.com/admin'],
        ]));
        $result = $normalizer->normalize([
            'https://example.com/page',
            'https://example.com/admin/secret',
        ]);
        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testDenyEndingsEmptyAllowsAll(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_deny_endings' => [],
        ]));
        $result = $normalizer->normalize(['https://example.com/file.pdf']);
        $this->assertSame(['https://example.com/file.pdf'], $result);
    }

    public function testDenyEndingsFiltersMatchingExtensions(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_deny_endings' => ['.pdf', '.zip'],
        ]));
        $result = $normalizer->normalize([
            'https://example.com/page',
            'https://example.com/file.pdf',
            'https://example.com/archive.zip',
        ]);
        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testDenyEndingsCaseInsensitive(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_deny_endings' => ['.pdf'],
        ]));
        $result = $normalizer->normalize(['https://example.com/File.PDF']);
        $this->assertSame([], $result);
    }

    public function testDenyEndingsUrlWithoutPathIsKept(): void
    {
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_deny_endings' => ['.pdf'],
        ]));
        $result = $normalizer->normalize(['https://example.com']);
        $this->assertSame(['https://example.com'], $result);
    }

    public function testFullPipelineAppliesAllFilters(): void
    {
        $normalizer = $this->makeNormalizer([
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['session'],
            'sp_allow_prefixes' => ['https://example.com'],
            'sp_deny_prefixes' => ['https://example.com/admin'],
            'sp_deny_endings' => ['.pdf'],
        ]);
        $urls = [
            'https://example.com/page?session=abc',
            'https://example.com/page?session=abc',  // duplicate
            'https://example.com/admin/secret',      // denied prefix
            'https://other.com/page',                // not in allow list
            'https://example.com/file.pdf',          // denied ending
        ];
        $result = $normalizer->normalize($urls);
        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testStripQueryParamsActiveWithUnparsableUrlPassedThrough(): void
    {
        // parse_url('//')  returns false → URL is returned unchanged in stripConfiguredQueryParams
        $normalizer = $this->makeNormalizer($this->baseConfig([
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['utm_source'],
        ]));
        $result = $normalizer->normalize(['//']);
        $this->assertSame(['//'], $result);
    }
}
