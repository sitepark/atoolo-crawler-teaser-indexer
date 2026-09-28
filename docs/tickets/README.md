# Tickets: Next Major – Restarbeiten

Ergebnis des Abgleichs `docs/proposal-next_major.md` ↔ `src/` (2026-09-28). Reihenfolge = Abarbeitungsreihenfolge: erst kleine unabhängige Fixes, dann der Strukturumbau (08), danach alles, was auf den neuen Signaturen aufbaut.

Verifikation pro Ticket: `composer analyse`, `vendor/bin/phpunit --no-coverage`; ab 08 zusätzlich `bin/console crawler:scheduler-atoolo-crawler-teaser-indexer -vvv` gegen eine Test-Site.

| # | Ticket | Status |
|---|--------|--------|
| 01 | [Datetime-FieldExtractor wird nie gefragt](01-datetime-field-extractor.md) | erledigt |
| 02 | [XPath-Injection in FieldSource::meta()](02-xpath-injection-meta.md) | erledigt |
| 03 | [User-Agent CRLF & SSRF-Hinweis](03-user-agent-crlf-ssrf.md) | erledigt |
| 04 | [Cron-Ausdrücke früh validieren](04-schedule-cron-validation.md) | erledigt |
| 05 | [Truncation-Länge](05-truncation-length.md) | erledigt |
| 06 | [Indexer-Fehler gehen nicht verloren](06-indexer-errors-not-lost.md) | erledigt |
| 07 | [ExtractedDataInterface in eigene Datei](07-extracted-data-interface-file.md) | erledigt |
| 08 | [Steps dekorierbar machen (Variante A: Decorator)](08-decoratable-steps.md) | erledigt |
| 09 | [Pipeline durchgehend lazy](09-lazy-pipeline.md) | erledigt |
| 10 | [URLNormalizer aufteilen](10-split-url-normalizer.md) | offen |
| 11 | [RelevanceEvaluator typisieren](11-relevance-evaluator-typed.md) | offen |
| 12 | [Extension-Bag & SolrFieldContributor](12-extension-bag.md) | offen |
| 13 | [PipelineConfigFactoryTest](13-pipeline-config-factory-test.md) | offen |
| 14 | [Echtes E2E mit MockHttpClient](14-e2e-mock-http.md) | offen |
| 15 | [Doku-Cutover](15-docs-cutover.md) | offen |
| 16 | [Indizierungszeiten pro Kunde](16-schedule-per-customer.md) | offen (unabhängig) |
