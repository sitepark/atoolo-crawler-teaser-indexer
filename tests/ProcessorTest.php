<?php

declare(strict_types=1);

namespace Tests;

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
        $loggerMock = $this->createStub(LoggerInterface::class);
        $this->processor = new Processor($loggerMock);
    }
    /**
     * Tests that the sanitizeText method correctly processes input titles.
     * Verifies that only clean, safe, and properly formatted titles remain in the output.
     */
    public function testTextLetterProcessorRemovesTagsScriptsAndWhitespace(): void
    {
        $datetime = "12.10.12T00:00:00";
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
                "title" => str_repeat("a", 120) . "...",
                "introText" => str_repeat("b", 120) . "...",
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
}
