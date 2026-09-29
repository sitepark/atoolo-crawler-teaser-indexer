# 20 – Eigener HTTP-Client: SSRF, Limits, Content-Type

**Typ:** Security/Robustheit · **Blocker für 2.0** · Release-Review 2.0

## Hintergrund
Das Bundle nutzt den globalen `http_client` ohne eigene Optionen (`config/services.yaml`, `RequestExecutor::requestOptions()` setzt nur den User-Agent).

- **SSRF:** Ohne `sp_allow_prefixes` (Default) wird jedem http(s)-Link gefolgt. Reproduziert: `http://127.0.0.1:8983/solr/`, `http://169.254.169.254/latest/meta-data/`, `http://localhost/` werden abgefragt, der Titel der internen Seite landet im Suchindex. Redirects (Symfony-Default: 20) laufen an `LinkFilter`, Allow-Prefixes und robots.txt vorbei – der Hinweis „Allowlist per LinkFilter-Decorator“ aus Ticket 03 schützt davor nicht.
- **Keine Größengrenze:** `Fetcher` liest den ganzen Body (`src/Pipeline/Fetcher/Fetcher.php:64`), die 2-MB-Grenze gibt es nur im Parser; `URLCollector::discoverLinks()` (`:167`) baut vorher ungeprüft ein DOM. `/download.php?id=7` mit 300 MB → `memory_limit` → Fatal Error, nicht fangbar, beendet den Lauf für alle Sites und den Worker.
- **Kein Content-Type-Check:** PDFs/Bilder ohne gesperrte Endung werden als HTML geparst.
- **Keine Zeitgrenze:** `max_duration` = unbegrenzt, ein langsam tröpfelnder Server blockiert den Lauf.

## Umsetzung
- Eigener scoped Client `atoolo_crawler.http_client` in `services.yaml`, gewrappt in `NoPrivateNetworkHttpClient` (in http-client 6.4 enthalten, prüft auch Redirect-Ziele).
- Optionen: `max_redirects` (klein, oder 0 falls Ticket 22 Redirects selbst behandelt), `timeout`, `max_duration`.
- Bundle-Parameter `atoolo.crawler.allow_private_network` (Default `false`) für Installationen, die bewusst Intranet crawlen.
- Größenlimit per `on_progress` (Abbruch > N MB, Parameter), Content-Type nur `text/html` / `application/xhtml+xml`; anderes loggen (debug) und verwerfen.
- Bundle-Parameter bekommen Defaults im Bundle (siehe Ticket 28).

## Akzeptanzkriterien
- Link auf `http://127.0.0.1/` wird nicht abgefragt (Test mit `MockHttpClient` hinter dem Wrapper bzw. Unit-Test der Service-Definition).
- Response > Limit → verworfen, kein Fatal; Response `application/pdf` → verworfen.
- README: Sicherheitsabschnitt (Private Network, Limits, Opt-out).

## Dateien
`config/services.yaml`, `src/AtooloCrawlerTeaserIndexerBundle.php`, `src/Ports/RequestExecutor.php`, `src/Pipeline/Fetcher/Fetcher.php`, `tests/RequestExecutorTest.php`, `tests/FetcherTest.php`, `README.md`
