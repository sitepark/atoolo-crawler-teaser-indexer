# 32 – Betrieb: Lock, robots.txt-5xx, Charset, Logging

**Typ:** Robustheit · **Später** (2.x) · Release-Review 2.0

## Hintergrund
Vier kleinere, unabhängige Punkte:

1. **Kein Lock gegen überlappende Läufe.** `Schedule` ohne `->lock()`, `SitesRunner`/`PipelineRunner` ohne Lock; `config/packages/lock.yaml` ist konfiguriert, aber ungenutzt. Manueller Konsolen-Lauf + geplanter Lauf derselben Site: landet B's `update()` zwischen A's `update()` und A's Delete, löscht A B's Dokumente, dann B A's Rest → Quelle leer bis zum nächsten Lauf.
2. **robots.txt bei 5xx = „alles erlaubt“.** `RobotsTxtChecker.php:69` parst nach erschöpften Retries mit `getContent(false)` die HTML-Fehlerseite als robots.txt. RFC 9309: 5xx → alles verboten, 4xx → alles erlaubt.
3. **Charset nur aus dem HTTP-Header wird ignoriert.** UTF-8 und `<meta charset>` funktionieren; eine cp1252-Seite, die den Zeichensatz nur im `Content-Type`-Header nennt, verliert `„“–`.
4. **URLs mit Zugangsdaten im Log.** Entdeckte Links werden inkl. User-Info (`https://user:pw@…`) und Session-Parametern auf warning/error geloggt.

## Umsetzung
1. Pro Site ein Lock (`LockFactory`, Key `crawler-<sp_id>`) in `PipelineRunner`; belegt → Site mit Warnung überspringen.
2. robots.txt: 5xx/Transportfehler → disallow all (mit Log), 4xx → allow all.
3. Charset aus `Content-Type` an DomCrawler übergeben, wenn im HTML keiner steht.
4. User-Info vor dem Loggen entfernen (kleiner Helper).

## Akzeptanzkriterien
- Je Punkt ein Test.

## Dateien
`src/Application/PipelineRunner.php`, `config/services.yaml`, `src/Pipeline/Collector/RobotsTxtChecker.php`, `src/Pipeline/Fetcher/Fetcher.php`, `src/Pipeline/Parser/Parser.php`, zugehörige Tests
