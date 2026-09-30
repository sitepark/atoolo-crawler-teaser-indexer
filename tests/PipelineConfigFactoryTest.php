<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfigFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PipelineConfigFactoryTest extends TestCase
{
    private function factory(): PipelineConfigFactory
    {
        return new PipelineConfigFactory(new NullLogger());
    }

    public function testCreatesConfigForValidSite(): void
    {
        $config = $this->factory()->create([
            'sp_id' => 'site-1',
            'sp_user_agent' => 'SiteBot/1.0',
        ]);

        $this->assertSame('site-1', $config->id());
        $this->assertSame('SiteBot/1.0', $config->userAgent());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSiteIdProvider(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['sp_id' => null]];
        yield 'empty string' => [['sp_id' => '']];
        yield 'integer' => [['sp_id' => 42]];
        yield 'array' => [['sp_id' => ['site-1']]];
    }

    /**
     * An invalid site config is rejected instead of yielding a half-valid
     * config; the site loop catches this per site.
     *
     * @dataProvider invalidSiteIdProvider
     *
     * @param array<string, mixed> $siteData
     */
    public function testRejectsSiteWithoutValidId(array $siteData): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Site config is missing required field "sp_id".');

        $this->factory()->create($siteData);
    }
}
