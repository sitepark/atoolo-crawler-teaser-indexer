<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Pipeline\Collector\LinkFilter;
use Atoolo\CrawlerIndexer\Pipeline\Collector\RobotsTxtCheckerInterface;
use Atoolo\CrawlerIndexer\Pipeline\Collector\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LinkFilterTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function config(array $config = []): PipelineConfig
    {
        return new PipelineConfig(new PipelineConfigHelper($config, new NullLogger()));
    }

    /**
     * @param list<string>         $urls
     * @param array<string, mixed> $config
     * @param list<string>         $bundleDenyEndings
     *
     * @return list<string>
     */
    private function filter(array $urls, array $config = [], array $bundleDenyEndings = []): array
    {
        $filter = new LinkFilter($this->createStub(RobotsTxtCheckerInterface::class), $bundleDenyEndings);

        return $filter->filter($urls, $this->config($config));
    }

    public function testWithoutRulesKeepsEverything(): void
    {
        $urls = ['https://example.com/a', 'https://other.com/b'];

        $this->assertSame($urls, $this->filter($urls));
    }

    public function testAllowPrefixesKeepOnlyMatchingUrls(): void
    {
        $result = $this->filter(
            ['https://example.com/allowed/page1', 'https://example.com/denied/page2', 'https://other.com/page'],
            ['sp_allow_prefixes' => ['https://example.com/allowed']],
        );

        $this->assertSame(['https://example.com/allowed/page1'], $result);
    }

    public function testDenyPrefixesDropMatchingUrls(): void
    {
        $result = $this->filter(
            ['https://example.com/page', 'https://example.com/admin/secret'],
            ['sp_deny_prefixes' => ['https://example.com/admin']],
        );

        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testDenyWinsOverAllow(): void
    {
        $result = $this->filter(
            ['https://example.com/page', 'https://example.com/admin/secret'],
            ['sp_allow_prefixes' => ['https://example.com'], 'sp_deny_prefixes' => ['https://example.com/admin']],
        );

        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testDenyEndingsFromConfigAndBundleAreCombined(): void
    {
        $result = $this->filter(
            ['https://example.com/page', 'https://example.com/file.pdf', 'https://example.com/image.jpg'],
            ['sp_deny_endings' => ['.pdf']],
            ['.jpg'],
        );

        $this->assertSame(['https://example.com/page'], $result);
    }

    public function testDenyEndingsAreCaseInsensitive(): void
    {
        $this->assertSame([], $this->filter(['https://example.com/File.PDF'], [], ['.pdf']));
        $this->assertSame([], $this->filter(['https://example.com/file.pdf'], ['sp_deny_endings' => ['.PDF']]));
    }

    public function testDenyEndingsIgnoreUrlsWithoutPath(): void
    {
        $this->assertSame(['https://example.com'], $this->filter(['https://example.com'], ['sp_deny_endings' => ['.pdf']]));
    }

    public function testRobotsTxtIsAppliedWhenEnabled(): void
    {
        $robots = $this->createMock(RobotsTxtCheckerInterface::class);
        $robots->expects($this->once())
            ->method('filterAllowed')
            ->with(['https://example.com/a', 'https://example.com/b'])
            ->willReturn(['https://example.com/a']);

        $result = (new LinkFilter($robots, []))->filter(
            ['https://example.com/a', 'https://example.com/b', 'https://example.com/c.pdf'],
            $this->config(['sp_respect_robots_txt' => true, 'sp_deny_endings' => ['.pdf']]),
        );

        // robots.txt only sees what the other rules let through.
        $this->assertSame(['https://example.com/a'], $result);
    }

    public function testRobotsTxtIsSkippedWhenDisabledOrNothingLeft(): void
    {
        $robots = $this->createMock(RobotsTxtCheckerInterface::class);
        $robots->expects($this->never())->method('filterAllowed');
        $filter = new LinkFilter($robots, []);

        $filter->filter(['https://example.com/a'], $this->config(['sp_respect_robots_txt' => false]));
        $filter->filter(
            ['https://other.com/a'],
            $this->config(['sp_respect_robots_txt' => true, 'sp_allow_prefixes' => ['https://example.com']]),
        );
    }

    /**
     * The bug this split fixes: the old normalizer checked allow/deny before
     * canonicalizing, so a differently spelled link slipped past a deny
     * prefix. With canonicalize → filter it is caught.
     */
    public function testCanonicalizedLinkCannotSlipPastADenyPrefix(): void
    {
        $config = $this->config([
            'sp_deny_prefixes' => ['https://example.com/intern'],
            'sp_strip_query_params_active' => true,
            'sp_strip_query_params' => ['utm'],
        ]);
        $links = ['https://EXAMPLE.com:443/intern/?utm=1#x', 'https://example.com/public'];

        $canonical = (new UrlCanonicalizer())->canonicalize($links, $config);
        $result = (new LinkFilter($this->createStub(RobotsTxtCheckerInterface::class), []))->filter($canonical, $config);

        $this->assertSame(['https://example.com/public'], $result);
    }
}
