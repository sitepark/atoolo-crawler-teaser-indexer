<?php

declare(strict_types=1);

namespace Atoolo\CrawlerIndexer\Tests;

use Atoolo\CrawlerIndexer\Pipeline\Parser\FieldSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

final class FieldSourceTest extends TestCase
{
    private const HTML = <<<'HTML'
        <html>
          <head><meta property="og:title" content="Meta Titel"></head>
          <body>
            <div class="teaser">
              <h2>Block Titel</h2>
              <time datetime="2026-01-14">14. Januar 2026</time>
              <p class="intro">  Einleitung mit Rand  </p>
            </div>
          </body>
        </html>
        HTML;

    private function makeSource(string $html = self::HTML): FieldSource
    {
        return new FieldSource(
            new Crawler($html),
            $this->createStub(LoggerInterface::class),
        );
    }

    public function testTextReturnsTrimmedTextOfFirstMatch(): void
    {
        $this->assertSame('Einleitung mit Rand', $this->makeSource()->text('.intro'));
    }

    public function testTextReturnsNullWhenSelectorDoesNotMatch(): void
    {
        $this->assertNull($this->makeSource()->text('.does-not-exist'));
    }

    public function testAttrReturnsAttributeOfFirstMatch(): void
    {
        $this->assertSame('2026-01-14', $this->makeSource()->attr('time', 'datetime'));
    }

    public function testAttrReturnsNullWhenAttributeIsAbsent(): void
    {
        $this->assertNull($this->makeSource()->attr('h2', 'datetime'));
    }

    public function testAttrReturnsNullWhenSelectorDoesNotMatch(): void
    {
        $this->assertNull($this->makeSource()->attr('.does-not-exist', 'datetime'));
    }

    public function testMetaReturnsContentOfMatchingProperty(): void
    {
        $this->assertSame('Meta Titel', $this->makeSource()->meta('og:title'));
    }

    public function testMetaReturnsNullWhenPropertyIsAbsent(): void
    {
        $this->assertNull($this->makeSource()->meta('og:description'));
    }

    /**
     * The property used to be interpolated into the XPath expression, so this
     * value turned into `@property='x' or @property='og:title'` and matched a
     * tag it does not name. It must be a plain string comparison.
     */
    public function testMetaPropertyCannotInjectIntoTheQuery(): void
    {
        $this->assertNull($this->makeSource()->meta("x' or @property='og:title"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function quotedPropertyProvider(): iterable
    {
        yield 'single quote' => ["it's:title"];
        yield 'double quote' => ['say:"title"'];
        yield 'both quotes' => ['it\'s:"title"'];
    }

    /**
     * @dataProvider quotedPropertyProvider
     */
    public function testMetaMatchesPropertiesContainingQuotesExactly(string $property): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="Falsch">'
            . '<meta property="' . htmlspecialchars($property, ENT_QUOTES) . '" content="Richtig">'
            . '</head><body></body></html>';

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $source = new FieldSource(new Crawler($html), $logger);

        $this->assertSame('Richtig', $source->meta($property));
    }

    public function testMetaReturnsFirstMatchingTag(): void
    {
        $html = '<html><head>'
            . '<meta property="og:title" content="  Erster  ">'
            . '<meta property="og:title" content="Zweiter">'
            . '</head><body></body></html>';

        $this->assertSame('Erster', $this->makeSource($html)->meta('og:title'));
    }

    public function testVisibleTextLeavesOutScriptsAndStyles(): void
    {
        $source = $this->makeSource('<html><head><style>p{}</style></head><body>'
            . '<main>Sichtbar <script>var x = 1;</script>und <noscript>kein JS</noscript>lesbar'
            . '<template><p>Vorlage</p></template></main></body></html>');

        $this->assertSame('Sichtbar und lesbar', $source->visibleText('main'));
    }

    public function testVisibleTextWithoutSelectorCoversTheWholeBlock(): void
    {
        $this->assertSame(
            'Block Titel 14. Januar 2026 Einleitung mit Rand',
            $this->makeSource()->visibleText(),
        );
    }

    public function testVisibleTextReturnsNullWithoutMatchOrText(): void
    {
        $this->assertNull($this->makeSource()->visibleText('.does-not-exist'));
        $this->assertNull($this->makeSource('<html><body><main> <script>x</script> </main></body></html>')->visibleText('main'));
    }

    /**
     * A malformed selector must not abort the block - it is logged and treated
     * like "no match", so a single bad config entry cannot lose a document.
     */
    public function testInvalidSelectorIsLoggedAndYieldsNull(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to parse CSS selector', $this->anything());

        $source = new FieldSource(new Crawler(self::HTML), $logger);

        $this->assertNull($source->text('>>> not a selector'));
    }

    public function testInvalidAttrSelectorIsLoggedAndYieldsNull(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to parse CSS attr', $this->anything());

        $source = new FieldSource(new Crawler(self::HTML), $logger);

        $this->assertNull($source->attr('>>> not a selector', 'datetime'));
    }

    /**
     * Scoping is relative to the passed node: a FieldSource built from a split
     * block must not see the rest of the page.
     */
    public function testSelectorsAreScopedToThePassedNode(): void
    {
        $page = new Crawler('<html><body>'
            . '<div class="a"><h2>Erster</h2></div>'
            . '<div class="b"><h2>Zweiter</h2><p class="intro">B-Intro</p></div>'
            . '</body></html>');

        $node = $page->filterXPath('//div[@class="b"]')->getNode(0);
        self::assertNotNull($node);

        $source = new FieldSource(new Crawler($node), $this->createStub(LoggerInterface::class));

        $this->assertSame('Zweiter', $source->text('h2'));
        $this->assertSame('B-Intro', $source->text('.intro'));
        $this->assertNull($source->text('.a h2'));
    }

    /**
     * The memory guarantee of the class: no public API may hand the Crawler (or
     * any DOM object) back out, otherwise an extractor could retain a whole
     * parsed page for the rest of the run.
     */
    public function testNoPublicMethodExposesTheDom(): void
    {
        $reflection = new \ReflectionClass(FieldSource::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ('__construct' === $method->getName()) {
                continue;
            }

            $returnType = $method->getReturnType();
            $this->assertInstanceOf(
                \ReflectionNamedType::class,
                $returnType,
                sprintf('%s() must declare a simple return type', $method->getName()),
            );
            $this->assertContains(
                $returnType->getName(),
                ['string', 'int', 'bool', 'float', 'array', 'void'],
                sprintf('%s() must not return a DOM object', $method->getName()),
            );
        }
    }

    public function testVisibleTextReturnsNullAndLogsErrorForInvalidSelector(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Failed to parse CSS selector');

        $source = new FieldSource(new Crawler(self::HTML), $logger);

        $this->assertNull($source->visibleText('a['));
    }

    public function testMetaReturnsNullAndLogsErrorWhenQueryFails(): void
    {
        $crawler = $this->createStub(Crawler::class);
        $crawler->method('filterXPath')->willThrowException(new \RuntimeException('query failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Failed to parse meta tag');

        $source = new FieldSource($crawler, $logger);

        $this->assertNull($source->meta('og:title'));
    }
}
