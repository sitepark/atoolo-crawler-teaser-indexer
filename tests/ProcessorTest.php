<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Config\PipelineConfig;
use Atoolo\CrawlerIndexer\Config\PipelineConfigHelper;
use Atoolo\CrawlerIndexer\Dto\ExtractedData;
use Atoolo\CrawlerIndexer\Dto\ExtractedDataInterface;
use Atoolo\CrawlerIndexer\Pipeline\Processor\Processor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProcessorTest extends TestCase
{
    private Processor $processor;
    private PipelineConfig $config;

    protected function setUp(): void
    {
        $ctx = [
            'sp_title_max_chars' => 120,
            'sp_introText_max_chars' => 120,
        ];

        $logger = $this->createStub(LoggerInterface::class);
        $helper = new PipelineConfigHelper($ctx, $logger);
        $this->config = new PipelineConfig($helper);
        $this->processor = new Processor($logger);
    }

    public function testTextLetterProcessorRemovesTagsScriptsAndWhitespace(): void
    {
        $datetime = new \DateTimeImmutable('2012-10-12T00:00:00', new \DateTimeZone('UTC'));
        $input = [
            new ExtractedData('https://example.com/1', '<p>Hello <b>World</b></p>', '<p>Dies ist <b>eine</b> Einleitung.</p>', $datetime),
            new ExtractedData('https://example.com/2', "<script>alert('XSS');</script>Test", "<script>alert('bad');</script>Kurztext", $datetime),
            new ExtractedData('https://example.com/3', '   &uuml;berzeugt   ', '   &auml;u&szlig;erst  <i>wichtig</i>   ', $datetime),
            new ExtractedData('https://example.com/4', '', 'Soll ignoriert werden (kein Titel)', $datetime),
            new ExtractedData('https://example.com/5', '       ', '   ', $datetime),
            new ExtractedData('https://example.com/6', str_repeat('a', 200), str_repeat('b', 300), $datetime),
            new ExtractedData('https://example.com/7', "<span style='color:red'>Red Text</span>", "<span style='color:red'>Roter <b>Intro</b> Text</span>", $datetime),
        ];

        $expected = [
            new ExtractedData('https://example.com/1', 'Hello World', 'Dies ist eine Einleitung.', $datetime),
            new ExtractedData('https://example.com/2', 'Test', 'Kurztext', $datetime),
            new ExtractedData('https://example.com/3', 'überzeugt', 'äußerst wichtig', $datetime),
            new ExtractedData('https://example.com/6', str_repeat('a', 119) . '…', str_repeat('b', 119) . '…', $datetime),
            new ExtractedData('https://example.com/7', 'Red Text', 'Roter Intro Text', $datetime),
        ];

        $result = $this->processor->sanitizeText($input, $this->config);
        $this->assertEquals($expected, iterator_to_array($result));
    }

    /**
     * maxChars is the length of the result, the ellipsis included. Lengths are
     * counted in characters, not bytes.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function truncationProvider(): iterable
    {
        yield 'shorter than max' => [str_repeat('a', 9), str_repeat('a', 9)];
        yield 'exactly max' => [str_repeat('a', 10), str_repeat('a', 10)];
        yield 'one over max' => [str_repeat('a', 11), str_repeat('a', 9) . '…'];
        yield 'multibyte' => [str_repeat('ä', 11), str_repeat('ä', 9) . '…'];
    }

    /**
     * @dataProvider truncationProvider
     */
    public function testTruncatedTextIsAtMostMaxChars(string $text, string $expected): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $ctx = ['sp_title_max_chars' => 10, 'sp_introText_max_chars' => 10];
        $config = new PipelineConfig(new PipelineConfigHelper($ctx, $logger));
        $processor = new Processor($logger);

        $result = iterator_to_array($processor->sanitizeText([
            new ExtractedData('https://example.com/page', $text, $text),
        ], $config));

        $this->assertCount(1, $result);
        $this->assertSame($expected, $result[0]->getTitle());
        $this->assertSame($expected, $result[0]->getIntroText());
        $this->assertLessThanOrEqual(10, mb_strlen($result[0]->getTitle()));
    }

    public function testItemWithoutIntroTextKeyOmitsIntroTextField(): void
    {
        $datetime = new \DateTimeImmutable('2024-01-01T00:00:00', new \DateTimeZone('UTC'));
        $input = [
            new ExtractedData('https://example.com/page', 'Title', null, $datetime),
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input, $this->config));

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getIntroText());
        $this->assertSame('Title', $result[0]->getTitle());
    }

    public function testItemWithoutDatetimeKeyOmitsDatetimeField(): void
    {
        $input = [
            new ExtractedData('https://example.com/page', 'Title'),
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input, $this->config));

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getDate());
    }

    public function testEmptyCleanedTitleAfterStrippingIsDiscarded(): void
    {
        $input = [
            new ExtractedData('https://example.com/page', '<script>alert(1)</script>'),
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input, $this->config));

        $this->assertSame([], $result);
    }

    public function testTruncateWithEmptyStringAfterCleaningLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $ctx = ['sp_title_max_chars' => 120];
        $helper = new PipelineConfigHelper($ctx, $logger);
        $config = new PipelineConfig($helper);
        $processor = new Processor($logger);

        $input = [
            new ExtractedData('https://example.com/page', '   '),
        ];

        $result = iterator_to_array($processor->sanitizeText($input, $config));

        $this->assertSame([], $result);
    }

    public function testIntroTextEmptyStringIsNotIncludedInOutput(): void
    {
        $input = [
            new ExtractedData('https://example.com/page', 'Title', ''),
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input, $this->config));

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getIntroText());
    }

    public function testCatchBlockIsTriggeredWhenItemThrows(): void
    {
        $ctx = ['sp_title_max_chars' => 120, 'sp_introText_max_chars' => 120];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $helper = new PipelineConfigHelper($ctx, $logger);
        $config = new PipelineConfig($helper);
        $processor = new Processor($logger);

        $throwingItem = $this->createMock(ExtractedDataInterface::class);
        $throwingItem->method('getTitle')->willThrowException(new \RuntimeException('unexpected'));

        $result = iterator_to_array($processor->sanitizeText([$throwingItem], $config));

        $this->assertSame([], $result);
    }

    /**
     * The Processor is shared across sites, so each call truncates with the
     * maxChars of the config it is given.
     */
    public function testOneProcessorServesSitesWithDifferentConfigs(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $processor = new Processor($logger);
        $input = [new ExtractedData('https://example.com/', str_repeat('a', 20))];

        $short = new PipelineConfig(new PipelineConfigHelper(['sp_title_max_chars' => 5], $logger));
        $long = new PipelineConfig(new PipelineConfigHelper(['sp_title_max_chars' => 50], $logger));

        $this->assertSame('aaaa…', iterator_to_array($processor->sanitizeText($input, $short))[0]->getTitle());
        $this->assertSame(str_repeat('a', 20), iterator_to_array($processor->sanitizeText($input, $long))[0]->getTitle());
    }
}
