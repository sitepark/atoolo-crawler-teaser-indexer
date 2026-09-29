# 17 – Indexer-Cleanup absichern

**Typ:** Bug · **Blocker für 2.0** · Release-Review 2.0

## Hintergrund
Der Indexer ist der einzige Step, der Daten löscht (`deleteExcludingProcessId`). Der Schutz davor ist heute schwach (`src/Pipeline/Indexer/Indexer.php:108-137`):

1. **Solr-Status ≠ 0 löscht trotzdem.** `update()` meldet einen Fehlerstatus nur an den ProgressHandler (`:117-121`); danach laufen Delete + Commit. Die `IndexingErrorsException` kommt erst in `CrawlerPipeline::index()` – zu spät, die Quelle ist dann leer. (Solarium wirft bei HTTP ≥ 400 meist selbst, der Fall ist selten, aber real.)
2. **Nur absolute Schwelle.** `successCount <= sp_cleanup_threshold` schützt nur vor 0…50 Dokumenten. Szenario: Site mit 400 Teasern, Firewall antwortet 340 Detailseiten mit 403 (wird vom `Fetcher` still verworfen) → 60 > 50 → 340 Teaser gelöscht. Negative Schwelle wird akzeptiert (`PipelineConfigHelper::int`) → mit `-1` löscht ein leerer Lauf alles.
3. **`update()` vor der Schwellenprüfung.** Bei `ThresholdNotMetException` sind die neuen Dokumente (neue Hash-IDs) bereits an Solr geschickt, aber nicht committet. Der nächste Commit eines anderen Indexers oder autoCommit macht sie sichtbar → Dubletten neben den alten Dokumenten (besonders beim ersten 2.0-Lauf: alte URL-IDs + neue Hash-IDs).
4. **Testlücken** (escaped Mutants): Entfernen von `setField('crawl_process_id', …)` (`:93`) fällt nicht auf – ohne das Feld löscht die Delete-Query die frisch geschriebenen Dokumente. Kein Test prüft, dass bei nicht erreichter Schwelle **nicht** gelöscht wird, dass Delete und Dokumente dieselbe `processId` tragen, oder die Grenze `<=` vs `<`.

## Umsetzung
- Schwelle **vor** `update()` prüfen: erst zählen, bei Nichterreichen gar nichts an Solr senden.
- `update()`-Status ≠ 0 → Exception vor Delete/Commit.
- Relative Schwelle: neuer optionaler Key `sp_cleanup_min_ratio` (z.B. `0.5`): Cleanup nur, wenn `successCount >= ratio × bisherige Dokumentzahl der Quelle` (Solr-Count `sp_source:<id>` vor dem Update). Default so wählen, dass bestehende Configs sicher sind (Vorschlag 0.5); bei Nichterreichen `ThresholdNotMetException` mit beiden Werten.
- `sp_cleanup_threshold` auf ≥ 0 klemmen (Validierung → Ticket 23).
- Grenze dokumentieren: „mehr als N Dokumente“.

## Akzeptanzkriterien
- Status ≠ 0 → kein `deleteExcludingProcessId`, kein `commit`, Site fehlgeschlagen.
- Schwelle nicht erreicht → kein `update`, kein Delete.
- 400 alte Dokumente, 60 neue, ratio 0.5 → kein Delete.
- Test: jedes Dokument trägt `crawl_process_id` == drittes Argument von `deleteExcludingProcessId`.
- Test für die Grenze (`== threshold` schlägt fehl, `threshold + 1` löscht).
- CHANGELOG (Ticket 28) nennt das neue Verhalten.

## Dateien
`src/Pipeline/Indexer/Indexer.php`, `src/Config/PipelineConfig.php`, `src/Exception/ThresholdNotMetException.php`, `tests/IndexerTest.php`, `tests/CrawlerPipelineE2ETest.php`
