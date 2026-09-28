# 08 – Steps dekorierbar machen

**Proposal:** §3 „Ein Interface pro Step → per Decorator modifizierbar", §8 `services.yaml` · **Typ:** Architektur (Breaking)
**Entscheidung (2026-09-28):** Variante A – Config als Methodenparameter, Steps als Container-Services, Anpassung per Symfony-Decorator.

## Hintergrund
Ziele nach ISO 9126: Lesbarkeit, Erweiterbarkeit, Austauschbarkeit.

Das Bundle wird über ein **Commons-Projekt** genutzt, auf das nach und nach ältere Kundenprojekte umgestellt werden (eine Installation pro Kunde). Damit gibt es zwei Ebenen, die denselben Step anpassen können – Commons für alle, ein Kunde zusätzlich. Symfony-Decorators stapeln sich automatisch (`decoration_priority`), ohne dass eine Ebene die andere kennen muss.

Verworfen:
- **Factory pro Step:** doppelte Anzahl Interfaces; wer innere Bausteine (Fetcher, Evaluator) austauschen will, braucht eine Kette von Factories.
- **Factory mit `protected createX()`-Methoden:** Anpassung per Vererbung; bei Commons + Kunde nur als Vererbungskette möglich (`KundenFactory extends CommonsFactory`) – vergisst ein Kunde das, fällt die Commons-Anpassung still weg.

Heute geht Dekorieren gar nicht: `services.yaml` schließt alle Steps aus, `CrawlerPipelineFactory` baut sie pro Site mit `new`, weil `PipelineConfig` per Konstruktor injiziert wird. Zusätzlich ist `FetcherInterface` leer, `Fetcher` implementiert es nicht, `URLCollector` hängt am konkreten `Fetcher`.

Ziel für ein Projekt:

```php
#[AsDecorator(ParserInterface::class)]
final class MyParser implements ParserInterface
{
    public function __construct(#[AutowireDecorated] private ParserInterface $inner) {}

    public function extractData(array $htmlData, PipelineConfig $config): \Generator
    {
        foreach ($this->inner->extractData($htmlData, $config) as $entry) {
            yield $entry; // vorher/nachher eingreifen
        }
    }
}
```

## Umsetzung
- `PipelineConfig` wird **Methodenparameter** statt Konstruktor-Argument. Konvention für die Lesbarkeit: immer **letzter Parameter**, überall `$config`.
  - `URLCollectorInterface::collect(PipelineConfig)`
  - `FetcherInterface::fetchUrls(list<string>, PipelineConfig)`
  - `RobotsTxtCheckerInterface::filterAllowed(list<string>, PipelineConfig)`
  - `RequestExecutorInterface::request/requestChunk(…, PipelineConfig)` – User-Agent pro Request als Header statt `withOptions()` im Konstruktor
  - `ParserInterface::extractData(…, PipelineConfig)`, `RelevanceEvaluatorInterface::relevant(…, PipelineConfig)`
  - `ProcessorInterface::sanitizeText(…, PipelineConfig)`
  - `IndexerInterface::prepare(…, PipelineConfig)` / `doIndex(…, PipelineConfig)`
  - `URLNormalizer::normalize(…, PipelineConfig)`
- `CrawlerPipeline` wird Singleton-Service mit `run(PipelineConfig)`; `CrawlerPipelineFactory` entfällt, `PipelineRunner` ruft `CrawlerPipeline::run($config)`.
- `services.yaml`: Excludes für die Steps entfernen, Interface → Default-Implementierung aliasen, Parameter (`denyEndings`, `retryStatusCodes`, Solr-Services, `!tagged_iterator` Field-Extractors) direkt an die jeweiligen Services binden.
- Abhängigkeiten nur noch auf Interfaces (`URLCollector` → `FetcherInterface`, `RobotsTxtCheckerInterface`, …), damit ein dekorierter Baustein überall greift.
- **Zustand eines Laufs an genau einer Stelle zurücksetzen:** `RequestExecutor` (`lastRequestPerHost`) und `RobotsTxtChecker` (Cache) implementieren `ResetInterface`; `CrawlerPipeline::run()` setzt sie am Anfang zurück. Damit gilt auch ein neuer robots.txt-Stand bei jedem Lauf im langlebigen Messenger-Worker.
- Keine per-Site-Werte als Properties auf Services (`Indexer::$source` → lokale Variable).
- Bestehende Signaturen bleiben ansonsten gleich (`array` bleibt `array`) – die Umstellung auf `iterable` gehört zu Ticket 09.

## Akzeptanzkriterien
- Kein Step wird mehr mit `new` in Produktionscode gebaut.
- Container-Test: ein registrierter Decorator für `ParserInterface` wird im Lauf aufgerufen; zwei gestapelte Decorators (Commons + Kunde) greifen beide.
- Zwei Sites hintereinander teilen keinen Throttle-/robots-State.
- Alle bestehenden Tests portiert, `composer analyse` grün, Crawler läuft lokal.

## Dateien
`config/services.yaml`, `src/Pipeline/**`, `src/Ports/RequestExecutor*.php`, `src/Application/PipelineRunner.php`, `src/Pipeline/CrawlerPipelineFactory.php` (löschen), Tests
