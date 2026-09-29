# 25 – Öffentliche API für 2.x festziehen

**Typ:** API · **Vor 2.0** (jede Änderung danach ist ein Bruch) · nach 22/23 · Release-Review 2.0

## Hintergrund
Was das Commons-Projekt anfasst, ist ab 2.0 eingefroren. Offene Punkte:

1. **`PipelineConfig` baut man über den Helper:** `new PipelineConfig(new PipelineConfigHelper([...], $logger))` (`src/Config/PipelineConfig.php:9`) – so machen es E2E- und Decoration-Test. Das friert den Helper mit ein. Decorators (LinkFilter-Allowlist, FieldExtractor) können **keine eigenen Keys** lesen.
2. **`ExtractedDataInterface` + Neubau per Konstruktor:** Processor (`Processor.php:~97`) und das Decorator-Muster in `tests/StepDecorationTest.php:146` bauen `new ExtractedData(url, title, intro, date)`. Kommt ein Feld dazu (Bilder, Ticket 12), verlieren alle Decorators es still, und jeder Implementierer des Interfaces bricht.
3. **Finalität uneinheitlich:** Step-Implementierungen (`Parser`, `Processor`, `Indexer`, `Fetcher`, `URLCollector`, `CrawlerPipeline`) sind nicht `final`, `LinkFilter`, `RobotsTxtChecker`, `RequestExecutor`, `RelevanceEvaluator` schon. Nicht-final lädt zur Vererbung ein, die das Proposal (§10) ablehnt.
4. **Keine `@api`/`@internal`-Marker.** `FetcherInterface`, `RobotsTxtCheckerInterface`, `RequestExecutorInterface` sind als Aliase de facto öffentlich.
5. **Toter öffentlicher Code:** `Config/ContentScoringRuleConfig` und `Pipeline/Parser/ContentScoringRule` referenzieren nur einander.
6. **Exceptions:** keine gemeinsame Basis; `StepExecution` ohne `Exception`-Suffix, Step-Name nur im Text; `ThresholdNotMetException` gibt Zahlen nicht als Properties heraus; teils ohne `declare(strict_types=1)` (auch `Parser.php`).
7. **`IndexerInterface::prepare(string $message)`** reicht ein Detail des ProgressHandlers durch; der Pipeline-Aufruf übergibt immer eine Konstante.

## Umsetzung
- `PipelineConfig` nur über `PipelineConfigFactory` (oder `PipelineConfig::fromArray()`), Helper `@internal`; `extra(string $key): mixed` für projektspezifische (Nicht-`sp_*`-)Keys.
- `ExtractedData` final, `withTitle()/withIntroText()/withDate()`; Interface entfernen oder als „nicht implementieren“ markieren. Processor und Doku-Beispiele auf Wither umstellen.
- Step-Implementierungen `final` (Tests, die `CrawlerPipeline` mocken, auf `PipelineRunner` umstellen).
- `@api` an Interfaces, `FieldSource`, `ExtractedData`, `PipelineConfig`, Exceptions; `@internal` am Rest.
- Tote Scoring-Klassen löschen.
- `CrawlerExceptionInterface`; `StepExecution` → `StepFailedException` mit `public readonly string $step`; Counts als Properties an `ThresholdNotMetException`.
- `prepare(string)` → `start(): void` (oder entfernen).

## Akzeptanzkriterien
- Decoration-Test zeigt einen LinkFilter-Decorator, der einen eigenen Key über `extra()` liest.
- Processor-Test: ein zusätzliches Feld bleibt erhalten (Wither).
- phpstan L9 grün; CHANGELOG listet alle Umbenennungen.

## Dateien
`src/Config/PipelineConfig*.php`, `src/Dto/*`, `src/Pipeline/**`, `src/Exception/*`, `tests/StepDecorationTest.php`, `tests/ProcessorTest.php`, weitere Tests
