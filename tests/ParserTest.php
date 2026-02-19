<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Services\TeaserRelevanceEvaluatorInterface;
use Atoolo\Crawler\Domain\Crawler\Steps\Parser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $ctx = new CrawlerConfigContext([
            'atoolo.crawler.title.present' => true,
            'atoolo.crawler.title.opengraph' => ['og:title'],
            'atoolo.crawler.title.prefix' => '',
            'atoolo.crawler.title.css' => ['h1', '#content h1', 'h1.h1'],
            'atoolo.crawler.title.max_chars' => 200,

            'atoolo.crawler.introText.present' => true,
            'atoolo.crawler.introText.required_field' => false,
            'atoolo.crawler.introText.opengraph' => [],
            'atoolo.crawler.introText.css' => ['.introText'],
            'atoolo.crawler.introText.max_chars' => 200,

            'atoolo.crawler.datetime.present' => true,
            'atoolo.crawler.datetime.required_field' => false,
            'atoolo.crawler.datetime.only-date' => true,
            'atoolo.crawler.datetime.opengraph' => [],
            'atoolo.crawler.datetime.css' => ['.date', '#content .date'],
        ]);

        $logger = $this->createStub(LoggerInterface::class);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $config = new CrawlerConfig($helper);

        $evaluator = $this->createStub(TeaserRelevanceEvaluatorInterface::class);
        $evaluator
            ->method('relevant')
            ->willReturn(true);

        $this->parser = new Parser(
            $logger,
            $config,
            $evaluator
        );
    }

    /**
     * @param array<int,array<string,mixed>> $result
     * @return array<int,array<string,mixed>>
     */
    private function normalizeDatetime(array $result): array
    {
        return array_map(static function (array $t): array {
            if (isset($t['datetime']) && $t['datetime'] instanceof \DateTimeInterface) {
                $t['datetime'] = $t['datetime']->format(DATE_ATOM);
            }
            return $t;
        }, $result);
    }

    public function testExtractsTitleFromOgMeta(): void
    {
        $htmlData = <<<HTML
<html>
  <head><meta property="og:title" content="Meta Title"></head>
  <body>
    <h1 class="h1">Meta Title</h1>
    <div class="date">2026-01-14</div>
    <div class="introText">Einleitungs Text Extrahiert</div>
  </body>
</html>
HTML;

        $result = $this->parser->extractTeasers([
            [
                'url'  => 'https://example.com/page1',
                'html' => $htmlData,
            ],
        ]);
        $result = $this->normalizeDatetime($result);

        $this->assertSame([
            [
                'url' => 'https://example.com/page1',
                'title' => 'Meta Title',
                'introText' => 'Einleitungs Text Extrahiert',
                'datetime' => '2026-01-14T00:00:00+00:00',
            ]
        ], $result);
    }

    public function testExtractsTitleFromH1IfNoMeta(): void
    {
        $html = <<<HTML
<html>
  <body id="content">
    <h1>Main Heading</h1>
    <div class="introText">Einleitungs Text Extrahiert</div>
    <div class="date">2026-01-14</div>
  </body>
</html>
HTML;

        $result = $this->parser->extractTeasers([
            ['url' => 'https://example.com/page2', 'html' => $html]
        ]);
        $result = $this->normalizeDatetime($result);

        $this->assertSame([
            [
                'url' => 'https://example.com/page2',
                'title' => 'Main Heading',
                'introText' => 'Einleitungs Text Extrahiert',
                'datetime' => '2026-01-14T00:00:00+00:00',
            ]
        ], $result);
    }

    public function testSkipsWhenNoTitle(): void
    {
        $html = "<html><body><p>No title here</p></body></html>";

        $result = $this->parser->extractTeasers([
            ['url' => 'https://example.com/page3', 'html' => $html]
        ]);

        $this->assertSame([], $result);
    }

    public function testSkipsEmptyHtml(): void
    {
        $result = $this->parser->extractTeasers([
            ['url' => 'https://example.com/empty', 'html' => '']
        ]);

        $this->assertSame([], $result);
    }
}
