# 09 – Pipeline durchgehend lazy

**Proposal:** §3 Streaming · **Typ:** Architektur
**Voraussetzung:** 08

## Hintergrund
Das HTML ist durch die Chunks begrenzt, die Dokumente aber nicht: `CrawlerPipeline` sammelt alle `ExtractedData` in `$rawDocuments`, der Processor-Generator wird per `iterator_to_array` ausgelesen, `Indexer::doIndex(array)` braucht `count()` und dedupliziert auf dem Array.

Realistische Erwartung: der Solr-Updater puffert die Dokumente bis `update()` ohnehin → Speichergewinn moderat; Gewinn ist vor allem Konsistenz (kein Zwischen-Array, echte Kette).

## Umsetzung
- `CrawlerPipeline::run()`: `collect()` → `parse()` → `process()` → `index()` als Generator-Kette, kein Zwischen-Array.
- Parser nimmt `iterable` von Seiten (Chunks flatten oder Chunks durchreichen), Processor bleibt Generator.
- `IndexerInterface::doIndex(iterable, PipelineConfig)`.
- Indexer:
  - Dedup streamend über `array<string, true>` aus `sha1(signature)`.
  - Dokumente beim Iterieren bauen und `addDocument()`; `progressHandler->start($n)` erst nach dem Durchlauf mit der tatsächlichen Anzahl, danach `advance($n)` (Dauer stimmt dank `prepare()` weiterhin).
- Fehler-Guards: da erst der Indexer konsumiert, Step-Exceptions weiterhin mit `StepExecution` kennzeichnen (Wrapper-Generatoren pro Step wie heute `parse()`).
- „Processor returned no data"-Warnung → Indexer bei 0 Einträgen (Threshold greift ohnehin).

## Akzeptanzkriterien
- Kein `iterator_to_array` / Sammel-Array zwischen den Steps.
- Statuszeile zeigt korrekte `processed/total`.
- Test: Generator-Kette wird nur einmal konsumiert, Dedup funktioniert streamend, Step-Exception kommt als `StepExecution` an.

## Dateien
`src/Pipeline/CrawlerPipeline.php`, `src/Pipeline/Parser/*`, `src/Pipeline/Processor/*`, `src/Pipeline/Indexer/*`, Tests
