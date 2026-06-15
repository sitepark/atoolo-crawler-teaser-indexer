<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Steps\Processor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProcessorTest extends TestCase
{
    private Processor $processor;
    /**
     * Sets up the test environment before each test.
     * Creates a mock logger and initializes the Processor instance.
     */
    protected function setUp(): void
    {
        $ctx = new CrawlerConfigContext([
            'sp_title_max_chars' => 120,
            'sp_introText_max_chars' => 120,
        ]);

        $logger = $this->createStub(LoggerInterface::class);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $config = new CrawlerConfig($helper);
        $this->processor = new Processor($logger, $config);
    }
    /**
     * Tests that the sanitizeText method correctly processes input titles.
     * Verifies that only clean, safe, and properly formatted titles remain in the output.
     */
    public function testTextLetterProcessorRemovesTagsScriptsAndWhitespace(): void
    {
        $datetime = new \DateTimeImmutable("2012-10-12T00:00:00", new \DateTimeZone('UTC'));
        $input = [
            [
                "url"   => "https://example.com/1",
                "title" => "<p>Hello <b>World</b></p>",
                "introText" => "<p>Dies ist <b>eine</b> Einleitung.</p>",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/2",
                "title" => "<script>alert('XSS');</script>Test",
                "introText" => "<script>alert('bad');</script>Kurztext",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/3",
                "title" => "   &uuml;berzeugt   ",
                "introText" => "   &auml;u&szlig;erst  <i>wichtig</i>   ",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/4",
                "title" => "",
                "introText" => "Soll ignoriert werden (kein Titel)",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/5",
                "title" => "       ",
                "introText" => "   ",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/6",
                "title" => str_repeat("a", 200),
                "introText" => str_repeat("b", 300),
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/7",
                "title" => "<span style='color:red'>Red Text</span>",
                "introText" => "<span style='color:red'>Roter <b>Intro</b> Text</span>",
                "datetime" => $datetime,
            ],
        ];


        $expected = [
            [
                "url"   => "https://example.com/1",
                "title" => "Hello World",
                "introText" => "Dies ist eine Einleitung.",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/2",
                "title" => "Test",
                "introText" => "Kurztext",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/3",
                "title" => "überzeugt",
                "introText" => "äußerst wichtig",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/6",
                "title" => str_repeat("a", 120) . "…",
                "introText" => str_repeat("b", 120) . "…",
                "datetime" => $datetime,
            ],
            [
                "url"   => "https://example.com/7",
                "title" => "Red Text",
                "introText" => "Roter Intro Text",
                "datetime" => $datetime,
            ],
        ];


        $result = $this->processor->sanitizeText($input);
        $this->assertSame($expected, iterator_to_array($result));
    }

    public function testItemWithoutIntroTextKeyOmitsIntroTextField(): void
    {
        $datetime = new \DateTimeImmutable("2024-01-01T00:00:00", new \DateTimeZone('UTC'));
        $input = [
            ['url' => 'https://example.com/page', 'title' => 'Title', 'datetime' => $datetime],
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input));

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('introText', $result[0]);
        $this->assertSame('Title', $result[0]['title']);
    }

    public function testItemWithoutDatetimeKeyOmitsDatetimeField(): void
    {
        $input = [
            ['url' => 'https://example.com/page', 'title' => 'Title'],
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input));

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('datetime', $result[0]);
    }

    public function testEmptyCleanedTitleAfterStrippingIsDiscarded(): void
    {
        $input = [
            ['url' => 'https://example.com/page', 'title' => '<script>alert(1)</script>'],
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input));

        $this->assertSame([], $result);
    }

    public function testTruncateWithEmptyStringAfterCleaningLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $ctx = new CrawlerConfigContext(['sp_title_max_chars' => 120]);
        $helper = new CrawlerConfigHelper($ctx, $logger);
        $config = new CrawlerConfig($helper);
        $processor = new Processor($logger, $config);

        // An item with only whitespace results in empty string after cleanString,
        // which then triggers the warning in truncate()
        $input = [
            ['url' => 'https://example.com/page', 'title' => '   '],
        ];

        $result = iterator_to_array($processor->sanitizeText($input));

        $this->assertSame([], $result);
    }

    public function testIntroTextEmptyStringIsNotIncludedInOutput(): void
    {
        $input = [
            ['url' => 'https://example.com/page', 'title' => 'Title', 'introText' => ''],
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input));

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('introText', $result[0]);
    }

    public function testCatchBlockIsTriggeredWhenItemTitleIsInvalidType(): void
    {
        $ctx    = new CrawlerConfigContext(['sp_title_max_chars' => 120, 'sp_introText_max_chars' => 120]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $helper    = new CrawlerConfigHelper($ctx, $logger);
        $config    = new CrawlerConfig($helper);
        $processor = new Processor($logger, $config);

        // Processor.php has declare(strict_types=1), so cleanString(int) causes TypeError
        $result = iterator_to_array($processor->sanitizeText([
            ['url' => 'https://example.com/', 'title' => 123],
        ]));

        $this->assertSame([], $result);
    }
}
