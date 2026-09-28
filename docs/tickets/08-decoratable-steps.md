# 08 – Steps dekorierbar machen

**Proposal:** §3 „Ein Interface pro Step → per Decorator modifizierbar", §8 `services.yaml` · **Typ:** Architektur (Breaking)

## Hintergrund
Symfony kann nur **Container-Services** dekorieren. Ein Projekt soll z.B. schreiben können:

```php
#[AsDecorator(ParserInterface::class)]
final class MyParser implements ParserInterface
{
    public function __construct(#[AutowireDecorated] private ParserInterface $inner) {}
    public function extractData(iterable $pages, PipelineConfig $config): \Generator { /* vor/nach $inner */ }
}
```

Heute geht das nicht: `services.yaml` schließt alle Steps aus, `CrawlerPipelineFactory` baut sie pro Site mit `new`, weil `PipelineConfig` per **Konstruktor** injiziert wird. Es gibt keinen Service, an den ein Decorator andocken kann. Zusätzlich ist `FetcherInterface` leer, `Fetcher` implementiert es nicht, `URLCollector` hängt am konkreten `Fetcher`.

## Umsetzung
- `PipelineConfig` wird **Methodenparameter** statt Konstruktor-Argument (so wie im Proposal-Interface-Table):
  - `URLCollectorInterface::collect(PipelineConfig)`
  - `ParserInterface::extractData(iterable, PipelineConfig)`
  - `ProcessorInterface::sanitizeText(iterable, PipelineConfig)`
  - `IndexerInterface::prepare(…, PipelineConfig)` / `doIndex(…, PipelineConfig)`
  - `FetcherInterface::fetchUrls(list<string>, PipelineConfig)`, `RobotsTxtCheckerInterface`, `RelevanceEvaluatorInterface`, `URLNormalizer` analog.
- `CrawlerPipeline` wird Singleton-Service, `run(PipelineConfig)`; `CrawlerPipelineFactory` entfällt, `PipelineRunner` ruft `CrawlerPipeline::run($config)`.
- `services.yaml`: Excludes für Steps entfernen, Interface → Default-Implementierung aliasen, Parameter (`denyEndings`, `retryStatusCodes`, Solr-Services, `!tagged_iterator` Field-Extractors) direkt an die jeweiligen Services binden.
- **Zustand pro Lauf:** `RequestExecutor` hat Per-Host-Throttle-State (`lastRequestPerHost`), `RobotsTxtChecker` evtl. einen Cache. Diese dürfen zwischen Sites nicht lecken → `ResetInterface` implementieren bzw. State am Anfang von `run()` zurücksetzen (Pipeline ruft reset), oder State in ein pro Lauf erzeugtes Objekt auslagern.
- Keine per-Site-Werte als Properties auf Services (`Indexer::$source` → lokale Variable).

## Akzeptanzkriterien
- Kein Step wird mehr mit `new` in Produktionscode gebaut.
- Test (Kernel-/Container-Test): ein registrierter Decorator für `ParserInterface` wird im Lauf aufgerufen.
- Zwei Sites hintereinander teilen keinen Throttle-/robots-State.
- Alle bestehenden Tests portiert, `composer analyse` grün, Crawler läuft lokal.

## Dateien
`config/services.yaml`, `src/Pipeline/**`, `src/Ports/RequestExecutor*.php`, `src/Application/PipelineRunner.php`, `src/Pipeline/CrawlerPipelineFactory.php` (löschen), Tests

## Hinweis
Ticket 09 (lazy) ändert dieselben Signaturen → Interfaces hier bereits mit `iterable` statt `array` schneiden.
