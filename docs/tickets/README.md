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
| 10 | [URLNormalizer aufteilen](10-split-url-normalizer.md) | erledigt |
| 11 | [RelevanceEvaluator typisieren](11-relevance-evaluator-typed.md) | erledigt |
| 12 | [Bilder extrahieren und indizieren](12-images.md) | später (bei Kundenanforderung) |
| 13 | [PipelineConfigFactoryTest](13-pipeline-config-factory-test.md) | erledigt |
| 14 | [Echtes E2E mit MockHttpClient](14-e2e-mock-http.md) | erledigt |
| 15 | [Doku-Cutover](15-docs-cutover.md) | erledigt |
| 16 | [Indizierungszeiten pro Kunde](16-schedule-per-customer.md) | offen (unabhängig) |

## Release-Review 2.0 (2026-09-29)

Voller Qualitäts-Review von `feature/next_major` vor dem Tag `2.0.0` (Korrektheit, Upgrade/Packaging, Design/Tests/Security). Baseline: `composer analyse` grün, 340 Tests grün (3× Zufallsreihenfolge), Coverage 95 % Zeilen, Infection-MSI 67 %.

Reihenfolge: **Blocker** (17–21) vor dem Release, dann **vor 2.0** (22–28, jede Änderung danach wäre ein Bruch; 23 + 24 zusammen, 28 als letztes, weil es die anderen im CHANGELOG sammelt), danach **später** (29–35, in 2.x ohne Bruch möglich). Innerhalb der Blocker zuerst 18 (klein) und 17, dann 19 → 20 (beide `RequestExecutor`), 21 unabhängig.

| # | Ticket | Priorität | Status |
|---|--------|-----------|--------|
| 17 | [Indexer-Cleanup absichern](17-indexer-cleanup-safety.md) | Blocker | offen |
| 18 | [`sp_id` validieren (Solr-Query)](18-validate-sp-id.md) | Blocker | offen |
| 19 | [RequestExecutor: Throttle, Fehler pro URL, Retry-After](19-request-executor-robustness.md) | Blocker | offen |
| 20 | [Eigener HTTP-Client: SSRF, Limits, Content-Type](20-http-client-hardening.md) | Blocker | offen |
| 21 | [Release-Packaging](21-release-packaging.md) | Blocker | offen |
| 22 | [Redirects und `FetchedPage`](22-redirects-fetched-page.md) | vor 2.0 | offen |
| 23 | [Site-Config streng validieren](23-strict-config-validation.md) | vor 2.0 | offen |
| 24 | [`sp_*`-Schema für 2.x festlegen](24-sp-schema-final.md) | vor 2.0 (Entscheidung) | offen |
| 25 | [Öffentliche API für 2.x festziehen](25-public-api-surface.md) | vor 2.0 | offen |
| 26 | [Indexer vom Search-Indexer-Interface lösen, eigener Status](26-indexer-search-interface.md) | vor 2.0 | offen |
| 27 | [Processor: Felder als Klartext behandeln](27-processor-plain-text.md) | vor 2.0 | offen |
| 28 | [Upgrade-Pfad: CHANGELOG, Defaults, Beispiel-Config, Doku](28-upgrade-docs.md) | vor 2.0 (zuletzt) | offen |
| 29 | [Datum strikt parsen](29-strict-date-parsing.md) | später | offen |
| 30 | [Query-String verlustfrei kanonisieren](30-lossless-query-canonicalization.md) | später | offen |
| 31 | [Block-Splitting linear machen](31-linear-block-splitting.md) | später | offen |
| 32 | [Betrieb: Lock, robots.txt-5xx, Charset, Logging](32-operational-robustness.md) | später | offen |
| 33 | [QS-Tooling: PHPUnit, Infection, CI-Matrix](33-qa-tooling.md) | später | offen |
| 34 | [Testlücken schließen, E2E ausbauen](34-test-gaps-e2e.md) | später | offen |
| 35 | [Aufräumen: toter Code, Namen, CLAUDE.md](35-cleanup-readability.md) | später | offen |
