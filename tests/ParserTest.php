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
            'sp_title_present' => true,
            'sp_title_opengraph' => ['og:title'],
            'sp_title_prefix' => '',
            'sp_title_css' => ['h1', '#content h1', 'h1.h1'],
            'sp_title_max_chars' => 200,

            'sp_introText_present' => true,
            'sp_introText_required_field' => false,
            'sp_introText_opengraph' => [],
            'sp_introText_css' => ['.introText'],
            'sp_introText_max_chars' => 200,

            'sp_datetime_present' => true,
            'sp_datetime_required_field' => false,
            'sp_datetime_only_date' => true,
            'sp_datetime_opengraph' => [],
            'sp_datetime_css' => ['.date', '#content .date'],
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
