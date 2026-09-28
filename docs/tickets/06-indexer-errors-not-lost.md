# 06 – Indexer-Fehler gehen nicht verloren

**Proposal:** §3 „CrawlResult statt void" · **Typ:** Robustheit
**Entscheidung:** Minimal-Lösung – kein volles `CrawlResult`, nur: Fehler dürfen nicht verloren gehen.

## Hintergrund
`CrawlerPipeline::startCrawler()` gibt `void` zurück. Bei `IndexerStatus->errors > 0` wird nur geloggt; `PipelineRunner` meldet „Successfully crawled", `SitesRunner` zählt die Site als erfolgreich, der Command liefert `SUCCESS`.

## Umsetzung
- `startCrawler()` gibt den `IndexerStatus` zurück.
- `PipelineRunner::run()` prüft `errors > 0` → wirft (z.B. neue `IndexingErrorsException` in `src/Exception/` mit Anzahl + Statuszeile).
- `SitesRunner` fängt das wie jeden Site-Fehler → Site in `failedSites` → Command `FAILURE`.
- Doppel-Logging vermeiden: Fehlermeldung in `CrawlerPipeline::index()` auf `info`/Statuszeile reduzieren, die Fehler-Meldung kommt einmal aus `SitesRunner`.

## Akzeptanzkriterien
- Indexer mit `errors = 1` → Site in `SitesRunResult->failedSites`, Command-Exit `1`.
- `errors = 0` → Verhalten wie bisher.
- Tests in `PipelineRunnerTest`, `SitesRunnerTest`.

## Dateien
`src/Pipeline/CrawlerPipeline.php`, `src/Application/PipelineRunner.php`, `src/Exception/…`, Tests
