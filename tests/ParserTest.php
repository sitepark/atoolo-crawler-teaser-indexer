<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\CrawlerConfigHelper;
use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Pipeline\Parser\RelevanceEvaluatorInterface;
use Atoolo\CrawlerIndexer\Pipeline\Parser\Parser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ParserTest extends TestCase
{
    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = $this->makeParser([
            'sp_title_opengraph' => ['og:title'],
            'sp_title_css' => ['h1', '#content h1', 'h1.h1'],
            'sp_title_max_chars' => 200,

            'sp_introText_present' => true,
            'sp_introText_required_field' => false,
            'sp_introText_opengraph' => [],
            'sp_introText_css' => ['.introText'],

            'sp_datetime_present' => true,
            'sp_datetime_required_field' => false,
            'sp_datetime_only_date' => true,
            'sp_datetime_opengraph' => [],
            'sp_datetime_css' => ['.date', '#content .date'],
        ]);
    }

    /**
     * Creates a Parser with merged config. Defaults provide minimal working config
     * (title via h1, introText and datetime inactive) so individual tests only
     * need to specify what they actually care about.
     *
     * @param array<string, mixed> $ctxOverrides
     */
    private function makeParser(array $ctxOverrides = [], bool $evaluatorReturns = true): Parser
    {
        $defaults = [
            'sp_title_prefix' => '',
            'sp_title_opengraph' => [],
            'sp_title_css' => ['h1'],
            'sp_title_max_chars' => 999,

            'sp_introText_present' => false,
            'sp_introText_required_field' => false,
            'sp_introText_opengraph' => [],
            'sp_introText_css' => ['.introText'],
            'sp_introText_max_chars' => 999,

            'sp_datetime_present' => false,
            'sp_datetime_required_field' => false,
            'sp_datetime_only_date' => true,
            'sp_datetime_opengraph' => [],
            'sp_datetime_css' => ['.date'],

            'sp_content_scoring_active' => false,
        ];

        $ctx = array_merge($defaults, $ctxOverrides);
        $logger = $this->createStub(LoggerInterface::class);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $config = new PipelineConfig($helper);

        $evaluator = $this->createStub(RelevanceEvaluatorInterface::class);
        $evaluator->method('relevant')->willReturn($evaluatorReturns);

        return new Parser($logger, $config, $evaluator);
    }

    /**
     * Converts ExtractedDataInterface[] to a normalized array structure for comparison.
     *
     * @param ExtractedDataInterface[] $result
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeDatetime(array $result): array
    {
        return array_map(static function (ExtractedDataInterface $t): array {
            $normalized = [
                'url' => $t->getUrl(),
                'title' => $t->getTitle(),
            ];
            if (null !== $t->getIntroText()) {
                $normalized['introText'] = $t->getIntroText();
            }
            if (null !== $t->getDate()) {
                $normalized['datetime'] = $t->getDate()->format(DATE_ATOM);
            }

            return $normalized;
        }, $result);
    }

    // --- Basic extraction ---

    public function testExtractsTitleFromOgMeta(): void
    {
        $html = <<<HTML
<html>
  <head><meta property="og:title" content="Meta Title"></head>
  <body>
    <h1 class="h1">Meta Title</h1>
    <div class="date">2026-01-14</div>
    <div class="introText">Einleitungs Text Extrahiert</div>
  </body>
</html>
HTML;

        $result = $this->normalizeDatetime($this->parser->extractData([
            ['url' => 'https://example.com/page1', 'html' => $html],
        ]));

        $this->assertSame([
            [
                'url' => 'https://example.com/page1',
                'title' => 'Meta Title',
                'introText' => 'Einleitungs Text Extrahiert',
                'datetime' => '2026-01-14T00:00:00+00:00',
            ],
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

        $result = $this->normalizeDatetime($this->parser->extractData([
            ['url' => 'https://example.com/page2', 'html' => $html],
        ]));

        $this->assertSame([
            [
                'url' => 'https://example.com/page2',
                'title' => 'Main Heading',
                'introText' => 'Einleitungs Text Extrahiert',
                'datetime' => '2026-01-14T00:00:00+00:00',
            ],
        ], $result);
    }

    public function testSkipsWhenNoTitle(): void
    {
        $html = '<html><body><p>No title here</p></body></html>';
        $result = $this->parser->extractData([
            ['url' => 'https://example.com/page3', 'html' => $html],
        ]);
        $this->assertSame([], $result);
    }

    public function testSkipsEmptyHtml(): void
    {
        $result = $this->parser->extractData([
            ['url' => 'https://example.com/empty', 'html' => ''],
        ]);
        $this->assertSame([], $result);
    }

    public function testMultipleItemsAllProcessed(): void
    {
        $html1 = '<html><body><h1>First</h1></body></html>';
        $html2 = '<html><body><h1>Second</h1></body></html>';

        $result = $this->parser->extractData([
            ['url' => 'https://example.com/1', 'html' => $html1],
            ['url' => 'https://example.com/2', 'html' => $html2],
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('First', $result[0]->getTitle());
        $this->assertSame('Second', $result[1]->getTitle());
    }

    // --- Huge HTML ---

    public function testSkipsHugeHtml(): void
    {
        $parser = $this->makeParser();
        $html = '<html><body><h1>Title</h1></body>' . str_repeat('x', 2_000_001) . '</html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertSame([], $result);
    }

    // --- Title prefix ---

    public function testTitlePrefixIsPrependedToTitle(): void
    {
        $parser = $this->makeParser(['sp_title_prefix' => 'PREFIX: ']);
        $html = '<html><body><h1>News</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertSame('PREFIX: News', $result[0]->getTitle());
    }

    // --- introText ---

    public function testIntroTextNotPresentResultsInNoIntroTextField(): void
    {
        $parser = $this->makeParser();
        $html = '<html><body><h1>Title</h1><div class="introText">Ignored</div></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getIntroText());
    }

    public function testIntroTextFoundIsIncludedInResult(): void
    {
        $parser = $this->makeParser([
            'sp_introText_present' => true,
            'sp_introText_css' => ['.intro'],
        ]);
        $html = '<html><body><h1>Title</h1><p class="intro">Lead text</p></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertSame('Lead text', $result[0]->getIntroText());
    }

    public function testIntroTextRequiredAndMissingSkipsDocument(): void
    {
        $parser = $this->makeParser([
            'sp_introText_present' => true,
            'sp_introText_required_field' => true,
            'sp_introText_css' => ['.introText'],
        ]);
        $html = '<html><body><h1>Title</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertSame([], $result);
    }

    public function testIntroTextNotFoundAndNotRequiredKeepsDocument(): void
    {
        $parser = $this->makeParser([
            'sp_introText_present' => true,
            'sp_introText_required_field' => false,
            'sp_introText_css' => ['.introText'],
        ]);
        $html = '<html><body><h1>Title</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getIntroText());
    }

    public function testIntroTextExtractedFromOgMeta(): void
    {
        $parser = $this->makeParser([
            'sp_introText_present' => true,
            'sp_introText_opengraph' => ['og:description'],
            'sp_introText_css' => [],
        ]);
        $html = <<<HTML
<html>
  <head><meta property="og:description" content="OG intro text"></head>
  <body><h1>Title</h1></body>
</html>
HTML;

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertSame('OG intro text', $result[0]->getIntroText());
    }

    // --- datetime ---

    public function testDateTimeNotPresentResultsInNoDatetimeField(): void
    {
        $parser = $this->makeParser();
        $html = '<html><body><h1>Title</h1><div class="date">2026-01-14</div></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getDate());
    }

    public function testDateTimeRequiredAndMissingSkipsDocument(): void
    {
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_required_field' => true,
            'sp_datetime_css' => ['.date'],
        ]);
        $html = '<html><body><h1>Title</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertSame([], $result);
    }

    public function testDateTimeNotFoundButNotRequiredKeepsDocument(): void
    {
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_required_field' => false,
            'sp_datetime_css' => ['.date'],
        ]);
        $html = '<html><body><h1>Title</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getDate());
    }

    public function testDateTimeExtractedFromOgMeta(): void
    {
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_opengraph' => ['article:published_time'],
            'sp_datetime_only_date' => false,
        ]);
        $html = <<<HTML
<html>
  <head><meta property="article:published_time" content="2026-03-15T10:00:00+00:00"></head>
  <body><h1>Title</h1></body>
</html>
HTML;

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result[0]->getDate());
        $this->assertSame('2026-03-15', $result[0]->getDate()->format('Y-m-d'));
    }

    public function testDateTimeExtractedFromTimeElementDatetimeAttribute(): void
    {
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_css' => ['time'],
            'sp_datetime_only_date' => true,
        ]);
        $html = <<<HTML
<html>
  <body>
    <h1>Title</h1>
    <time datetime="2026-05-20">May 20, 2026</time>
  </body>
</html>
HTML;

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result[0]->getDate());
        $this->assertSame('2026-05-20', $result[0]->getDate()->format('Y-m-d'));
    }

    public function testDateTimeFromCssTextContent(): void
    {
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_css' => ['.published'],
            'sp_datetime_only_date' => true,
        ]);
        $html = '<html><body><h1>Title</h1><span class="published">2026-07-04</span></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('2026-07-04', $result[0]->getDate()->format('Y-m-d'));
        $this->assertSame('00:00:00', $result[0]->getDate()->format('H:i:s'));
    }

    public function testDateTimeWithOnlyDateFalsePreservesTime(): void
    {
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_css' => ['.date'],
            'sp_datetime_only_date' => false,
        ]);
        $html = '<html><body><h1>Title</h1><div class="date">2026-01-14 08:30:00</div></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('2026-01-14', $result[0]->getDate()->format('Y-m-d'));
        $this->assertSame('08:30:00', $result[0]->getDate()->format('H:i:s'));
    }

    public function testDateTimeWithOnlyDateTrueButNonDateStringPassedThrough(): void
    {
        // "2026-01-14 12:00:00" does not match the strict Y-m-d format check,
        // so normalizeDateTimeRaw returns the raw string unchanged.
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_css' => ['.date'],
            'sp_datetime_only_date' => true,
        ]);
        $html = '<html><body><h1>Title</h1><div class="date">2026-01-14 12:00:00</div></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('2026-01-14', $result[0]->getDate()->format('Y-m-d'));
        // Time is preserved because the raw was not normalized to "YYYY-MM-DD 00:00:00"
        $this->assertSame('12:00:00', $result[0]->getDate()->format('H:i:s'));
    }

    // --- Content scoring ---

    public function testContentScoringFiltersOutDocument(): void
    {
        $parser = $this->makeParser(
            ['sp_content_scoring_active' => true],
            false, // evaluator rejects the document
        );
        $html = '<html><body><h1>Title</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertSame([], $result);
    }

    public function testContentScoringKeepsDocument(): void
    {
        $parser = $this->makeParser(
            ['sp_content_scoring_active' => true],
            true, // evaluator accepts the Document
        );
        $html = '<html><body><h1>Title</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('Title', $result[0]->getTitle());
    }

    // --- Exception paths (invalid selectors are caught and logged) ---

    public function testInvalidCssInIntroTextIsCaughtAndFieldOmitted(): void
    {
        // '[invalid' is an unclosed CSS attribute selector → CssSelector throws
        // findCssSelectorContent catches it and returns null
        $parser = $this->makeParser([
            'sp_introText_present' => true,
            'sp_introText_required_field' => false,
            'sp_introText_css' => ['[invalid'],
        ]);
        $html = '<html><body><h1>Title</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getIntroText());
    }

    public function testInvalidCssInDatetimeIsCaughtAndFieldOmitted(): void
    {
        // '[invalid' triggers exceptions in both findAttrByCss and findCssSelectorContent
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_required_field' => false,
            'sp_datetime_css' => ['[invalid'],
        ]);
        $html = '<html><body><h1>Title</h1></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getDate());
    }

    public function testParseDateTimeExceptionLogsWarningAndOmitsField(): void
    {
        // '@invalid' uses Unix timestamp syntax but is not a valid number → DateMalformedStringException
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_required_field' => false,
            'sp_datetime_css' => ['.date'],
            'sp_datetime_only_date' => false,
        ]);
        $html = '<html><body><h1>Title</h1><div class="date">@invalid</div></body></html>';

        $result = $parser->extractData([
            ['url' => 'https://example.com/', 'html' => $html],
        ]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getDate());
    }

    public function testExceptionFromEvaluatorIsCaughtByOuterTryCatch(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $evaluator = $this->createMock(RelevanceEvaluatorInterface::class);
        $evaluator->method('relevant')->willThrowException(new \RuntimeException('evaluator error'));

        $ctx = array_merge([
            'sp_title_prefix' => '',
            'sp_title_opengraph' => [],
            'sp_title_css' => ['h1'],
            'sp_title_max_chars' => 999,
            'sp_introText_present' => false,
            'sp_introText_required_field' => false,
            'sp_introText_opengraph' => [],
            'sp_introText_css' => [],
            'sp_introText_max_chars' => 999,
            'sp_datetime_present' => false,
            'sp_datetime_required_field' => false,
            'sp_datetime_only_date' => true,
            'sp_datetime_opengraph' => [],
            'sp_datetime_css' => [],
            'sp_content_scoring_active' => true,
        ]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $config = new PipelineConfig($helper);
        $parser = new Parser($logger, $config, $evaluator);

        $html = '<html><body><h1>Title</h1></body></html>';
        $result = $parser->extractData([['url' => 'https://example.com/', 'html' => $html]]);

        $this->assertSame([], $result);
    }

    public function testDateTimeRequiredAndUnparseableRawValueSkipsDocument(): void
    {
        $parser = $this->makeParser([
            'sp_datetime_present' => true,
            'sp_datetime_required_field' => true,
            'sp_datetime_only_date' => false,
            'sp_datetime_css' => ['.date'],
        ]);
        $html = '<html><body><h1>Title</h1><div class="date">@invalid</div></body></html>';

        $result = $parser->extractData([['url' => 'https://example.com/', 'html' => $html]]);

        $this->assertSame([], $result);
    }
}
