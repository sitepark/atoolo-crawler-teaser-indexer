<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfig;
use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Services\TeaserData;
use Atoolo\Crawler\Domain\Crawler\Services\TeaserDataInterface;
use Atoolo\Crawler\Domain\Crawler\Steps\Processor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProcessorTest extends TestCase
{
    private Processor $processor;

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

    public function testTextLetterProcessorRemovesTagsScriptsAndWhitespace(): void
    {
        $datetime = new \DateTimeImmutable('2012-10-12T00:00:00', new \DateTimeZone('UTC'));
        $input = [
            new TeaserData('https://example.com/1', '<p>Hello <b>World</b></p>', '<p>Dies ist <b>eine</b> Einleitung.</p>', $datetime),
            new TeaserData('https://example.com/2', "<script>alert('XSS');</script>Test", "<script>alert('bad');</script>Kurztext", $datetime),
            new TeaserData('https://example.com/3', '   &uuml;berzeugt   ', '   &auml;u&szlig;erst  <i>wichtig</i>   ', $datetime),
            new TeaserData('https://example.com/4', '', 'Soll ignoriert werden (kein Titel)', $datetime),
            new TeaserData('https://example.com/5', '       ', '   ', $datetime),
            new TeaserData('https://example.com/6', str_repeat('a', 200), str_repeat('b', 300), $datetime),
            new TeaserData('https://example.com/7', "<span style='color:red'>Red Text</span>", "<span style='color:red'>Roter <b>Intro</b> Text</span>", $datetime),
        ];

        $expected = [
            new TeaserData('https://example.com/1', 'Hello World', 'Dies ist eine Einleitung.', $datetime),
            new TeaserData('https://example.com/2', 'Test', 'Kurztext', $datetime),
            new TeaserData('https://example.com/3', 'überzeugt', 'äußerst wichtig', $datetime),
            new TeaserData('https://example.com/6', str_repeat('a', 120) . '…', str_repeat('b', 120) . '…', $datetime),
            new TeaserData('https://example.com/7', 'Red Text', 'Roter Intro Text', $datetime),
        ];

        $result = $this->processor->sanitizeText($input);
        $this->assertEquals($expected, iterator_to_array($result));
    }

    public function testItemWithoutIntroTextKeyOmitsIntroTextField(): void
    {
        $datetime = new \DateTimeImmutable('2024-01-01T00:00:00', new \DateTimeZone('UTC'));
        $input = [
            new TeaserData('https://example.com/page', 'Title', null, $datetime),
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input));

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getIntroText());
        $this->assertSame('Title', $result[0]->getTitle());
    }

    public function testItemWithoutDatetimeKeyOmitsDatetimeField(): void
    {
        $input = [
            new TeaserData('https://example.com/page', 'Title'),
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input));

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getDate());
    }

    public function testEmptyCleanedTitleAfterStrippingIsDiscarded(): void
    {
        $input = [
            new TeaserData('https://example.com/page', '<script>alert(1)</script>'),
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

        $input = [
            new TeaserData('https://example.com/page', '   '),
        ];

        $result = iterator_to_array($processor->sanitizeText($input));

        $this->assertSame([], $result);
    }

    public function testIntroTextEmptyStringIsNotIncludedInOutput(): void
    {
        $input = [
            new TeaserData('https://example.com/page', 'Title', ''),
        ];

        $result = iterator_to_array($this->processor->sanitizeText($input));

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->getIntroText());
    }

    public function testCatchBlockIsTriggeredWhenItemThrows(): void
    {
        $ctx = new CrawlerConfigContext(['sp_title_max_chars' => 120, 'sp_introText_max_chars' => 120]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $helper = new CrawlerConfigHelper($ctx, $logger);
        $config = new CrawlerConfig($helper);
        $processor = new Processor($logger, $config);

        $throwingItem = $this->createMock(TeaserDataInterface::class);
        $throwingItem->method('getTitle')->willThrowException(new \RuntimeException('unexpected'));

        $result = iterator_to_array($processor->sanitizeText([$throwingItem]));

        $this->assertSame([], $result);
    }
}
