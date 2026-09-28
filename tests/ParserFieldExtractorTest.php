<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Pipeline\Parser\FieldExtractorInterface;
use Atoolo\CrawlerIndexer\Pipeline\Parser\FieldSource;
use Atoolo\CrawlerIndexer\Pipeline\Parser\Parser;
use Atoolo\CrawlerIndexer\Pipeline\RelevanceEvaluator\RelevanceEvaluatorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the field extraction seam (7.1): a project extractor replaces the
 * built-in "OpenGraph then CSS" path for one field, and every failure mode
 * falls back to the built-in extraction instead of losing the document.
 */
final class ParserFieldExtractorTest extends TestCase
{
    private PipelineConfig $config;

    private const HTML = <<<'HTML'
        <html>
          <head><meta property="og:title" content="Meta Titel"></head>
          <body>
            <h1>CSS Titel</h1>
            <div class="introText">CSS Einleitung</div>
            <div class="date">2026-01-14</div>
            <script type="application/ld+json">{"headline":"JSON-LD Titel"}</script>
          </body>
        </html>
        HTML;

    /**
     * @param iterable<FieldExtractorInterface> $fieldExtractors
     * @param array<string, mixed>              $ctxOverrides
     */
    private function makeParser(
        iterable $fieldExtractors,
        array $ctxOverrides = [],
        ?LoggerInterface $logger = null,
    ): Parser {
        $ctx = array_merge([
            'sp_title_prefix' => '',
            'sp_title_opengraph' => ['og:title'],
            'sp_title_css' => ['h1'],
            'sp_title_max_chars' => 999,

            'sp_introText_present' => true,
            'sp_introText_required_field' => false,
            'sp_introText_opengraph' => [],
            'sp_introText_css' => ['.introText'],
            'sp_introText_max_chars' => 999,

            'sp_datetime_present' => true,
            'sp_datetime_required_field' => false,
            'sp_datetime_only_date' => true,
            'sp_datetime_opengraph' => [],
            'sp_datetime_css' => ['.date'],

            'sp_content_scoring_active' => false,
        ], $ctxOverrides);

        $logger ??= $this->createStub(LoggerInterface::class);
        $this->config = new PipelineConfig(new PipelineConfigHelper($ctx, $logger));

        $evaluator = $this->createStub(RelevanceEvaluatorInterface::class);
        $evaluator->method('relevant')->willReturn(true);

        return new Parser($logger, $evaluator, $fieldExtractors);
    }

    /**
     * @param iterable<FieldExtractorInterface> $fieldExtractors
     * @param array<string, mixed>              $ctxOverrides
     */
    private function parseOne(
        iterable $fieldExtractors,
        array $ctxOverrides = [],
        ?LoggerInterface $logger = null,
        string $html = self::HTML,
    ): ?ExtractedDataInterface {
        $result = iterator_to_array(
            $this->makeParser($fieldExtractors, $ctxOverrides, $logger)->extractData([
                ['url' => 'https://example.com/seite', 'html' => $html],
            ], $this->config),
            false,
        );

        return $result[0] ?? null;
    }

    /**
     * Builds an extractor for one field from a callback.
     */
    private function extractorFor(string $field, callable $extract): FieldExtractorInterface
    {
        $extractor = $this->createMock(FieldExtractorInterface::class);
        $extractor->method('supports')->willReturnCallback(
            static fn(string $asked): bool => $asked === $field,
        );
        $extractor->method('extract')->willReturnCallback(
            static fn(string $asked, FieldSource $source, PipelineConfig $config): mixed => $extract($source, $config),
        );

        return $extractor;
    }

    public function testExtractorOverridesBuiltInTitle(): void
    {
        // Reads from JSON-LD, which the built-in OpenGraph/CSS path cannot do.
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static function (FieldSource $source): ?string {
                $raw = $source->text('script[type="application/ld+json"]');
                if (null === $raw) {
                    return null;
                }
                $data = json_decode($raw, true);

                return is_array($data) && is_string($data['headline'] ?? null) ? $data['headline'] : null;
            },
        );

        $entry = $this->parseOne([$extractor]);

        self::assertNotNull($entry);
        $this->assertSame('JSON-LD Titel', $entry->getTitle());
        // Untouched fields still come from the built-in path.
        $this->assertSame('CSS Einleitung', $entry->getIntroText());
    }

    public function testExtractorOverridesIntroText(): void
    {
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_INTRO_TEXT,
            static fn(): string => 'Eigene Einleitung',
        );

        $entry = $this->parseOne([$extractor]);

        self::assertNotNull($entry);
        $this->assertSame('Eigene Einleitung', $entry->getIntroText());
        $this->assertSame('Meta Titel', $entry->getTitle());
    }

    public function testReturningNullFallsBackToBuiltInExtraction(): void
    {
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static fn(): ?string => null,
        );

        $entry = $this->parseOne([$extractor]);

        self::assertNotNull($entry);
        $this->assertSame('Meta Titel', $entry->getTitle());
    }

    public function testExtractorIsNotAskedForUnsupportedField(): void
    {
        $extractor = $this->createMock(FieldExtractorInterface::class);
        $extractor->method('supports')->willReturn(false);
        $extractor->expects($this->never())->method('extract');

        $entry = $this->parseOne([$extractor]);

        self::assertNotNull($entry);
        $this->assertSame('Meta Titel', $entry->getTitle());
    }

    public function testTitlePrefixIsAppliedToCustomValue(): void
    {
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static fn(): string => 'Eigener Titel',
        );

        $entry = $this->parseOne([$extractor], ['sp_title_prefix' => 'Stadt: ']);

        self::assertNotNull($entry);
        $this->assertSame('Stadt: Eigener Titel', $entry->getTitle());
    }

    public function testFirstNonNullExtractorWins(): void
    {
        $first = $this->extractorFor(FieldExtractorInterface::FIELD_TITLE, static fn(): ?string => null);
        $second = $this->extractorFor(FieldExtractorInterface::FIELD_TITLE, static fn(): string => 'Zweiter');
        $third = $this->extractorFor(FieldExtractorInterface::FIELD_TITLE, static fn(): string => 'Dritter');

        $entry = $this->parseOne([$first, $second, $third]);

        self::assertNotNull($entry);
        $this->assertSame('Zweiter', $entry->getTitle());
    }

    /**
     * The config is a per-site value and must reach the extractor as an
     * argument, since extractors are singletons for the whole run.
     */
    public function testExtractorReceivesTheCurrentSiteConfig(): void
    {
        $seen = null;
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static function (FieldSource $source, PipelineConfig $config) use (&$seen): string {
                $seen = $config;

                return 'Titel';
            },
        );

        $parser = $this->makeParser([$extractor], ['sp_id' => 'site-a']);
        iterator_to_array($parser->extractData([
            ['url' => 'https://example.com/', 'html' => self::HTML],
        ], $this->config), false);

        $this->assertInstanceOf(PipelineConfig::class, $seen);
        $this->assertSame('site-a', $seen->id());
    }

    // --- Failure modes: degrade the field, never lose the document ---

    public function testThrowingExtractorIsLoggedAndFallsBack(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with('[Parser] Field extractor failed, falling back', $this->anything());

        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static fn(): string => throw new \RuntimeException('kaputt'),
        );

        $entry = $this->parseOne([$extractor], [], $logger);

        self::assertNotNull($entry);
        $this->assertSame('Meta Titel', $entry->getTitle());
    }

    public function testThrowingExtractorDoesNotBlockLaterExtractors(): void
    {
        $broken = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static fn(): string => throw new \RuntimeException('kaputt'),
        );
        $working = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static fn(): string => 'Danach',
        );

        $entry = $this->parseOne([$broken, $working]);

        self::assertNotNull($entry);
        $this->assertSame('Danach', $entry->getTitle());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStringValueProvider(): iterable
    {
        yield 'int' => [42];
        yield 'array' => [['Titel']];
        yield 'object' => [new \stdClass()];
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
    }

    /**
     * @dataProvider invalidStringValueProvider
     */
    public function testInvalidStringValueIsLoggedAndFallsBack(mixed $value): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with('[Parser] Field extractor returned unexpected value, ignoring', $this->anything());

        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static fn(): mixed => $value,
        );

        $entry = $this->parseOne([$extractor], [], $logger);

        self::assertNotNull($entry);
        $this->assertSame('Meta Titel', $entry->getTitle());
    }

    /**
     * Formats the built-in `new \DateTimeImmutable($raw)` cannot parse - German
     * month names, surrounding text - are what a datetime extractor is for.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unparseableDateProvider(): iterable
    {
        yield 'german month name' => ['14. Januar 2026', '2026-01-14'];
        yield 'surrounding text' => ['Veröffentlicht am 14.01.2026', '2026-01-14'];
    }

    /**
     * @dataProvider unparseableDateProvider
     */
    public function testDateTimeExtractorHandlesFormatsTheBuiltInPathCannot(string $raw, string $expected): void
    {
        $html = '<html><body><h1>Titel</h1><div class="date">' . $raw . '</div></body></html>';
        $months = ['januar' => 1, 'februar' => 2, 'märz' => 3];

        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_DATETIME,
            static function (FieldSource $source) use ($months): ?\DateTimeImmutable {
                $text = mb_strtolower($source->text('.date') ?? '');
                if (1 === preg_match('/(\d{1,2})\.\s*(\p{L}+)\s+(\d{4})/u', $text, $m) && isset($months[$m[2]])) {
                    return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $m[3], $months[$m[2]], $m[1]));
                }
                if (1 === preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $m)) {
                    return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]));
                }

                return null;
            },
        );

        // Without the extractor the built-in path finds no date.
        $builtIn = $this->parseOne([], [], null, $html);
        self::assertNotNull($builtIn);
        $this->assertNull($builtIn->getDate());

        $entry = $this->parseOne([$extractor], [], null, $html);

        self::assertNotNull($entry);
        $this->assertSame($expected, $entry->getDate()?->format('Y-m-d'));
    }

    public function testDateTimeExtractorOverridesBuiltInDate(): void
    {
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_DATETIME,
            static fn(): \DateTimeInterface => new \DateTime('2030-06-01 12:00:00'),
        );

        $entry = $this->parseOne([$extractor]);

        self::assertNotNull($entry);
        // A mutable DateTime is accepted and converted.
        $this->assertInstanceOf(\DateTimeImmutable::class, $entry->getDate());
        $this->assertSame('2030-06-01 12:00', $entry->getDate()->format('Y-m-d H:i'));
    }

    public function testDateTimeExtractorReturningNullFallsBackToBuiltInDate(): void
    {
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_DATETIME,
            static fn(): ?\DateTimeInterface => null,
        );

        $entry = $this->parseOne([$extractor]);

        self::assertNotNull($entry);
        $this->assertSame('2026-01-14', $entry->getDate()?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDateTimeValueProvider(): iterable
    {
        yield 'string' => ['2030-06-01'];
        yield 'int' => [1_767_225_600];
        yield 'object' => [new \stdClass()];
    }

    /**
     * @dataProvider invalidDateTimeValueProvider
     */
    public function testInvalidDateTimeValueIsLoggedAndFallsBack(mixed $value): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with('[Parser] Field extractor returned unexpected value, ignoring', $this->anything());

        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_DATETIME,
            static fn(): mixed => $value,
        );

        $entry = $this->parseOne([$extractor], [], $logger);

        self::assertNotNull($entry);
        $this->assertSame('2026-01-14', $entry->getDate()?->format('Y-m-d'));
    }

    public function testCustomDateTimeSatisfiesRequiredField(): void
    {
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_DATETIME,
            static fn(): \DateTimeInterface => new \DateTimeImmutable('2026-03-01'),
        );

        $entry = $this->parseOne(
            [$extractor],
            [
                'sp_datetime_required_field' => true,
                'sp_datetime_css' => ['.gibt-es-nicht'],
            ],
        );

        self::assertNotNull($entry);
        $this->assertSame('2026-03-01', $entry->getDate()?->format('Y-m-d'));
    }

    public function testDateTimeExtractorIsNotAskedWhenDateTimeIsNotPresent(): void
    {
        $extractor = $this->createMock(FieldExtractorInterface::class);
        $extractor->method('supports')->willReturnCallback(
            static fn(string $field): bool => FieldExtractorInterface::FIELD_DATETIME === $field,
        );
        $extractor->expects($this->never())->method('extract');

        $entry = $this->parseOne([$extractor], ['sp_datetime_present' => false]);

        self::assertNotNull($entry);
        $this->assertNull($entry->getDate());
    }

    /**
     * A custom value cannot bypass the required-field rules: an empty title is
     * still a skipped document, not an empty one.
     */
    public function testCustomTitleStillSubjectToTitleRequiredRule(): void
    {
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static fn(): ?string => null,
        );

        $entry = $this->parseOne(
            [$extractor],
            ['sp_title_opengraph' => [], 'sp_title_css' => ['.gibt-es-nicht']],
        );

        $this->assertNull($entry);
    }

    public function testCustomIntroTextSatisfiesRequiredField(): void
    {
        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_INTRO_TEXT,
            static fn(): string => 'Von Hand',
        );

        $entry = $this->parseOne(
            [$extractor],
            [
                'sp_introText_required_field' => true,
                'sp_introText_css' => ['.gibt-es-nicht'],
            ],
        );

        self::assertNotNull($entry);
        $this->assertSame('Von Hand', $entry->getIntroText());
    }

    /**
     * With no extractors registered the Parser must behave exactly as before -
     * the seam is opt-in.
     */
    public function testNoExtractorsBehavesLikeBuiltInParser(): void
    {
        $entry = $this->parseOne([]);

        self::assertNotNull($entry);
        $this->assertSame('Meta Titel', $entry->getTitle());
        $this->assertSame('CSS Einleitung', $entry->getIntroText());
        $this->assertSame('2026-01-14', $entry->getDate()?->format('Y-m-d'));
    }

    /**
     * With a split page the extractor is called once per block and must only
     * see that block's DOM.
     */
    public function testExtractorIsScopedToEachSplitBlock(): void
    {
        $html = '<html><body><div id="content">'
            . '<article><h2>Block A</h2><p class="own">A-Text</p></article>'
            . '<article><h2>Block B</h2><p class="own">B-Text</p></article>'
            . '</div></body></html>';

        $extractor = $this->extractorFor(
            FieldExtractorInterface::FIELD_TITLE,
            static fn(FieldSource $source): ?string => $source->text('.own'),
        );

        $result = iterator_to_array(
            $this->makeParser($extractor ? [$extractor] : [], [
                'sp_split_html_document' => ['//article'],
                'sp_title_opengraph' => [],
                'sp_introText_present' => false,
                'sp_datetime_present' => false,
            ])->extractData([['url' => 'https://example.com/', 'html' => $html]], $this->config),
            false,
        );

        $this->assertSame(
            ['A-Text', 'B-Text'],
            array_map(static fn(ExtractedDataInterface $e): string => $e->getTitle(), $result),
        );
    }
}
