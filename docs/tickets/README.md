# Tickets: Next Major – Restarbeiten

Verifikation pro Ticket: `composer check`.

| # | Ticket | Status |
|---|--------|--------|
| 12 | [Bilder extrahieren und indizieren](12-images.md) | später (bei Kundenanforderung) |
| 16 | [Indizierungszeiten pro Kunde](16-schedule-per-customer.md) | offen (unabhängig) |

## Release-Review 2.0 (2026-09-29)

Voller Qualitäts-Review von `feature/next_major` vor dem Tag `2.0.0` (Korrektheit, Upgrade/Packaging, Design/Tests/Security). Baseline: `composer analyse` grün, 340 Tests grün (3× Zufallsreihenfolge), Coverage 95 % Zeilen, Infection-MSI 67 %.

Reihenfolge: **Blocker** (19–21) vor dem Release, dann **vor 2.0** (22–28, jede Änderung danach wäre ein Bruch; 23 + 24 zusammen, 28 als letztes, weil es die anderen im CHANGELOG sammelt), danach **später** (29–35, in 2.x ohne Bruch möglich). Innerhalb der Blocker zuerst 19 → 20 (beide `RequestExecutor`), 21 unabhängig.

| # | Ticket | Priorität | Status |
|---|--------|-----------|--------|
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
