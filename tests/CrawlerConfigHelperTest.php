<?php

declare(strict_types=1);

namespace Tests;

use Atoolo\Crawler\Config\CrawlerConfigContext;
use Atoolo\Crawler\Config\CrawlerConfigHelper;
use Atoolo\Crawler\Domain\Crawler\Services\LengthConditionConfig;
use Atoolo\Crawler\Domain\Crawler\Services\ScoreRuleConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CrawlerConfigHelperTest extends TestCase
{
    private function makeHelper(array $params, LoggerInterface $logger = null): CrawlerConfigHelper
    {
        $ctx = new CrawlerConfigContext($params);
        return new CrawlerConfigHelper($ctx, $logger ?? $this->createStub(LoggerInterface::class));
    }

    // --- bool() ---

    public function testBoolReturnsTrueForBoolTrue(): void
    {
        $helper = $this->makeHelper(['key' => true]);
        $this->assertTrue($helper->bool('key'));
    }

    public function testBoolReturnsFalseForBoolFalse(): void
    {
        $helper = $this->makeHelper(['key' => false]);
        $this->assertFalse($helper->bool('key'));
    }

    public function testBoolReturnsTrueForStringTrue(): void
    {
        $helper = $this->makeHelper(['key' => 'true']);
        $this->assertTrue($helper->bool('key'));
    }

    public function testBoolReturnsFalseForStringFalse(): void
    {
        $helper = $this->makeHelper(['key' => 'false']);
        $this->assertFalse($helper->bool('key'));
    }

    public function testBoolReturnsTrueForStringOne(): void
    {
        $helper = $this->makeHelper(['key' => '1']);
        $this->assertTrue($helper->bool('key'));
    }

    public function testBoolReturnsFalseForStringZero(): void
    {
        $helper = $this->makeHelper(['key' => '0']);
        $this->assertFalse($helper->bool('key'));
    }

    public function testBoolReturnsDefaultForMissingKey(): void
    {
        $helper = $this->makeHelper([]);
        $this->assertTrue($helper->bool('missing', true));
        $this->assertFalse($helper->bool('missing', false));
    }

    public function testBoolReturnsDefaultForInvalidStringAndLogsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $helper = $this->makeHelper(['key' => 'notabool'], $logger);
        $this->assertFalse($helper->bool('key', false));
    }

    public function testBoolReturnsDefaultForInvalidTypeAndLogsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $helper = $this->makeHelper(['key' => 42], $logger);
        $this->assertFalse($helper->bool('key', false));
    }

    // --- int() ---

    public function testIntReturnsIntValue(): void
    {
        $helper = $this->makeHelper(['key' => 7]);
        $this->assertSame(7, $helper->int('key'));
    }

    public function testIntReturnsDefaultForMissingKey(): void
    {
        $helper = $this->makeHelper([]);
        $this->assertSame(99, $helper->int('missing', 99));
    }

    public function testIntParsesStringDigit(): void
    {
        $helper = $this->makeHelper(['key' => '42']);
        $this->assertSame(42, $helper->int('key'));
    }

    public function testIntReturnsDefaultForInvalidAndLogsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $helper = $this->makeHelper(['key' => 'notanumber'], $logger);
        $this->assertSame(0, $helper->int('key'));
    }

    // --- string() ---

    public function testStringReturnsStringValue(): void
    {
        $helper = $this->makeHelper(['key' => 'hello']);
        $this->assertSame('hello', $helper->string('key'));
    }

    public function testStringReturnsDefaultForMissingKey(): void
    {
        $helper = $this->makeHelper([]);
        $this->assertSame('default', $helper->string('missing', 'default'));
    }

    public function testStringReturnsDefaultForInvalidTypeAndLogsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $helper = $this->makeHelper(['key' => 123], $logger);
        $this->assertSame('fallback', $helper->string('key', 'fallback'));
    }

    // --- nullableString() ---

    public function testNullableStringReturnsStringValue(): void
    {
        $helper = $this->makeHelper(['key' => 'hello']);
        $this->assertSame('hello', $helper->nullableString('key'));
    }

    public function testNullableStringReturnsNullForMissingKey(): void
    {
        $helper = $this->makeHelper([]);
        $this->assertNull($helper->nullableString('missing'));
    }

    public function testNullableStringReturnsNullForNullValue(): void
    {
        $helper = $this->makeHelper(['key' => null]);
        $this->assertNull($helper->nullableString('key'));
    }

    public function testNullableStringReturnsNullForEmptyString(): void
    {
        $helper = $this->makeHelper(['key' => '   ']);
        $this->assertNull($helper->nullableString('key'));
    }

    public function testNullableStringReturnsTrimmedValue(): void
    {
        $helper = $this->makeHelper(['key' => '  hello  ']);
        $this->assertSame('hello', $helper->nullableString('key'));
    }

    public function testNullableStringReturnsNullForInvalidTypeAndLogsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $helper = $this->makeHelper(['key' => 123], $logger);
        $this->assertNull($helper->nullableString('key'));
    }

    // --- intList() ---

    public function testIntListReturnsEmptyForMissingKey(): void
    {
        $helper = $this->makeHelper([]);
        $this->assertSame([], $helper->intList('missing'));
    }

    public function testIntListReturnsEmptyForNonArrayAndLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $helper = $this->makeHelper(['key' => 'notarray'], $logger);
        $this->assertSame([], $helper->intList('key'));
    }

    public function testIntListReturnsSortedUniqueInts(): void
    {
        $helper = $this->makeHelper(['key' => [3, 1, 2, 1]]);
        $this->assertSame([1, 2, 3], $helper->intList('key'));
    }

    public function testIntListParsesStringDigits(): void
    {
        $helper = $this->makeHelper(['key' => ['10', '20']]);
        $this->assertSame([10, 20], $helper->intList('key'));
    }

    public function testIntListSkipsInvalidItemsWithWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $helper = $this->makeHelper(['key' => [1, 'invalid', 2]], $logger);
        $this->assertSame([1, 2], $helper->intList('key'));
    }

    public function testIntListAcceptsNumericFloatAsInt(): void
    {
        $helper = $this->makeHelper(['key' => [1.5]]);
        $this->assertSame([1], $helper->intList('key'));
    }

    // --- stringList() ---

    public function testStringListReturnsEmptyForMissingKey(): void
    {
        $helper = $this->makeHelper([]);
        $this->assertSame([], $helper->stringList('missing'));
    }

    public function testStringListReturnsEmptyForNonArrayAndLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $helper = $this->makeHelper(['key' => 'notarray'], $logger);
        $this->assertSame([], $helper->stringList('key'));
    }

    public function testStringListFiltersOutEmptyStrings(): void
    {
        $helper = $this->makeHelper(['key' => ['a', '', 'b']]);
        $this->assertSame(['a', 'b'], $helper->stringList('key'));
    }

    public function testStringListReturnsValidStrings(): void
    {
        $helper = $this->makeHelper(['key' => ['foo', 'bar']]);
        $this->assertSame(['foo', 'bar'], $helper->stringList('key'));
    }

    // --- intStringList() ---

    public function testIntStringListReturnsEmptyForMissingKey(): void
    {
        $helper = $this->makeHelper([]);
        $this->assertSame([], $helper->intStringList('missing'));
    }

    public function testIntStringListReturnsEmptyForNonArrayAndLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $helper = $this->makeHelper(['key' => 'notarray'], $logger);
        $this->assertSame([], $helper->intStringList('key'));
    }

    public function testIntStringListFiltersOutEmptyStrings(): void
    {
        $helper = $this->makeHelper(['key' => ['a', '', 'b', 0]]);
        $result = $helper->intStringList('key');
        $this->assertNotContains('', $result);
        $this->assertContains('a', $result);
        $this->assertContains('b', $result);
    }

    // --- readScoreRules() ---

    public function testReadScoreRulesReturnsEmptyForMissingKey(): void
    {
        $helper = $this->makeHelper([]);
        $this->assertSame([], $helper->readScoreRules('missing'));
    }

    public function testReadScoreRulesReturnsEmptyForNonArrayAndLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $helper = $this->makeHelper(['key' => 'notarray'], $logger);
        $this->assertSame([], $helper->readScoreRules('key'));
    }

    public function testReadScoreRulesSkipsNonArrayEntryWithWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $helper = $this->makeHelper(['key' => ['invalid']], $logger);
        $this->assertSame([], $helper->readScoreRules('key'));
    }

    public function testReadScoreRulesReadsScore(): void
    {
        $helper = $this->makeHelper(['key' => [['sp_score' => 5]]]);
        $rules = $helper->readScoreRules('key');
        $this->assertCount(1, $rules);
        $this->assertInstanceOf(ScoreRuleConfig::class, $rules[0]);
        $this->assertSame(5, $rules[0]->score);
    }

    public function testReadScoreRulesReadsMatchAny(): void
    {
        $helper = $this->makeHelper(['key' => [
            ['sp_score' => 3, 'sp_match_any' => ['news', 'article']],
        ]]);
        $rules = $helper->readScoreRules('key');
        $this->assertCount(1, $rules);
        $this->assertSame(['news', 'article'], $rules[0]->matchAny);
    }

    public function testReadScoreRulesHandlesInvalidSpScore(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $helper = $this->makeHelper(['key' => [['sp_score' => 'invalid']]], $logger);
        $rules = $helper->readScoreRules('key');
        $this->assertCount(1, $rules);
        $this->assertSame(0, $rules[0]->score);
    }

    public function testReadScoreRulesHandlesInvalidMatchAny(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $helper = $this->makeHelper(['key' => [['sp_match_any' => 'notanarray']]], $logger);
        $rules = $helper->readScoreRules('key');
        $this->assertSame([], $rules[0]->matchAny);
    }

    public function testReadScoreRulesSkipsEmptyMatchAnyEntries(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');
        $helper = $this->makeHelper(['key' => [['sp_match_any' => ['valid', '', 123]]]], $logger);
        $rules = $helper->readScoreRules('key');
        $this->assertSame(['valid'], $rules[0]->matchAny);
    }

    public function testReadScoreRulesReadsConditionWithBodyTextLength(): void
    {
        $helper = $this->makeHelper(['key' => [
            [
                'sp_score' => -5,
                'sp_condition' => ['sp_body_text_length' => 100],
            ],
        ]]);
        $rules = $helper->readScoreRules('key');
        $this->assertCount(1, $rules);
        $this->assertInstanceOf(LengthConditionConfig::class, $rules[0]->condition);
        $this->assertSame(100, $rules[0]->condition->bodyTextLengthLt);
    }

    public function testReadScoreRulesHandlesInvalidCondition(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $helper = $this->makeHelper(['key' => [['sp_condition' => 'notanarray']]], $logger);
        $rules = $helper->readScoreRules('key');
        $this->assertNull($rules[0]->condition);
    }

    public function testReadScoreRulesHandlesInvalidBodyTextLength(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $helper = $this->makeHelper(['key' => [
            ['sp_condition' => ['sp_body_text_length' => 'invalid']],
        ]], $logger);
        $rules = $helper->readScoreRules('key');
        $this->assertNull($rules[0]->condition);
    }
}
