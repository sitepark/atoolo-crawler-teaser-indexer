# 34 – Testlücken schließen, E2E ausbauen

**Typ:** Tests · **Später** (2.x; Indexer-Teil gehört zu Ticket 17) · Release-Review 2.0

## Hintergrund
Coverage ist hoch (95 %), aber die überlebenden Mutanten zeigen ungetestetes Verhalten an riskanten Stellen:

- **URLCollector** (`src/Pipeline/Collector/URLCollector.php`): `++$depth` → `--$depth` (`:95`) und `$depth = -1` überleben → das `sp_extraction_depth`-Limit ist praktisch ungetestet. Nur die Links des letzten Chunks zu behalten (`:84`) überlebt → mehrteilige Level ungetestet. Forced URLs nur mit einem Chunk (`:148`). Das http(s)-Scheme-Filter (`:211`) ohne Allow-Prefixes ungetestet.
- **Parser**: `continue` → `break` bei leeren/zu großen Seiten überlebt → würde still den Rest des Chunks verwerfen.
- **Gar nicht getestet:** Redirects, Encodings, kaputte Links, die Payloads aus Ticket 27.
- **FieldExtractor-Autoconfiguration** („Service registrieren genügt“) wird nie in einem Container geprüft; `StepDecorationTest` deckt nur Parser-Decorators ab und prüft nicht, dass `CrawlerPipeline` den dekorierten Service bekommt.
- **Schwache Assertions:** mehrere `IndexerTest`-Fälle prüfen nur `assertInstanceOf(IndexerStatus::class, …)` (garantiert schon der Rückgabetyp); `IndexerTest` reicht `$this->config` als Nebeneffekt von `makeIndexer()` herum; Tests nach Implementierung benannt (`testCatchBlockIsTriggered…`, `testThrottleSleedsWhen…`).

## Umsetzung
- Lieber das E2E-Szenario (`tests/CrawlerPipelineE2ETest.php`, am lesbarsten) erweitern als weitere Mocks: Redirect, kaputter Link, doppelt kodierter Titel, Tiefe 2 mit mehreren Chunks, Nicht-HTML-Response.
- URLCollector-Tests für Tiefe und Mehr-Chunk-Level.
- Container-Test: ein Projekt-`FieldExtractorInterface`-Service ohne Tag wird vom Parser gefragt; `CrawlerPipeline` erhält den dekorierten Parser.
- Schwache Assertions durch Verhaltens-Assertions ersetzen, Tests nach Verhalten benennen.

## Akzeptanzkriterien
- Die genannten Mutanten sind getötet (Infection-Log aus Ticket 33).

## Dateien
`tests/CrawlerPipelineE2ETest.php`, `tests/URLCollectorTest.php`, `tests/ParserTest.php`, `tests/StepDecorationTest.php`, `tests/IndexerTest.php`, `tests/RequestExecutorTest.php`
