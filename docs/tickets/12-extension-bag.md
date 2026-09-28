# 12 – Extension-Bag & SolrFieldContributor

**Proposal:** §4.1, §7.2 · **Typ:** Feature (Erweiterbarkeit)
**Voraussetzung:** 08, 11

## Hintergrund
Kundenspezifische Zusatzdaten gibt es heute nicht – auch nicht unter anderem Namen: `ExtractedData` hat nur feste Felder, `FieldExtractorInterface` kann nur bestehende Felder ersetzen (steht so im Docblock), der `Indexer` setzt hartkodierte `setField()`s.

## Umsetzung
- `ExtractedData`: typisierte Bag `withExtension(object $ext): static` / `extension(string $class): ?object` / `extensions(): iterable`. Immutable; `Processor` übernimmt die Extensions beim Neubau des DTOs.
- Eingang: neues getaggtes `EntryEnricherInterface::enrich(ExtractedDataInterface $entry, FieldSource $source, PipelineConfig $config): ExtractedDataInterface` – wird im Parser nach der Feldextraktion, vor dem Relevance-Check aufgerufen. Autoconfiguration-Tag wie bei den Field-Extractors im Bundle registrieren. Fehler → loggen, Entry unverändert.
- Ausgang: `SolrFieldContributorInterface::contribute(Document $doc): void` (Solarium-Document bzw. das von `createDocument()` gelieferte Objekt). `Indexer` ruft für jede Extension, die es implementiert, `contribute()` nach den Kernfeldern auf. Kernfelder (`id`, `url`, `sp_source`, `crawl_process_id` …) dürfen nicht überschrieben werden → nach contribute erneut setzen oder vorher prüfen.
- Docblock von `FieldExtractorInterface` anpassen (Verweis auf Enricher für neue Felder).
- Dedup-Signatur (Indexer) bewusst ohne Extensions lassen und dokumentieren.

## Akzeptanzkriterien
- Beispiel-Extension im Test: Enricher hängt `PriceExtension` an → Solr-Doc hat `sp_price`.
- Extension überlebt Processor.
- Contributor, der `id` überschreibt, hat keine Wirkung.

## Dateien
`src/Dto/*`, `src/Pipeline/Parser/*`, `src/Pipeline/Processor/Processor.php`, `src/Pipeline/Indexer/*`, `src/AtooloCrawlerTeaserIndexerBundle.php`, Tests
