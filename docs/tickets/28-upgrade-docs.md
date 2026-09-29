# 28 – Upgrade-Pfad: CHANGELOG, Defaults, Beispiel-Config, Doku

**Typ:** Doku + Verhalten · **Vor 2.0**, als **letztes** vor dem Release (sammelt 17–27) · Release-Review 2.0

## Hintergrund
- **Fehlender Default bricht den ganzen Host:** `atoolo.crawler.deny_endings` hat keinen Default (`config/services.yaml:62`, `build()` setzt keinen). Nach `composer update` kompiliert der Host-Container nicht (`non-existent parameter`) – auch das Web, nicht nur der Crawler. Die `config/packages/atoolo_crawler_master.yaml` des Bundles lädt kein Host.
- **CHANGELOG falsch/unvollständig** (`CHANGELOG.md`, Abgleich gegen `v1.0.2`; `main` enthält den unveröffentlichten Commit `80d9540`):
  - „schedule name unchanged“ ist falsch: v1.0.2 `scheduler-atoolo-crawler-teaser-indexer` → jetzt `atoolo-crawler-teaser-indexer`. Worker mit `messenger:consume scheduler_scheduler-atoolo-crawler-teaser-indexer` findet den Transport nicht mehr → Crawler läuft nicht.
  - Fehlt: Startseiten werden indiziert; `sp_max_teaser` zählt Seiten; robots.txt blockiert jetzt auch die Link-Suche; `sp_start_urls`-Stringform wird verworfen; Content-Dedup senkt den Zähler für die Schwelle (erster Lauf kann abgelehnt werden); gleiche Inhalte auf verschiedenen URLs werden zusammengelegt; Messenger-Semantik (Site-Fehler erreichen Messenger-Retry nicht mehr); Klassen-/Methoden-Map für das Commons-Projekt.
  - `sp_relevance_content_selector` ist neu (→ Added), kein Typwechsel.
- **Beispiel-Config kaputt:** `config/example/exampleConfig.php` nutzt 1.x-Keys ohne Präfix (`crawling_sites`, `id`, `max_document`, …), Zeile 138 enthält ein literales `…,` (Include wirft `Undefined constant`), `match_any` enthält CSS-Selektoren, obwohl Keywords per `str_contains` gematcht werden, keine 2.0-Keys. atoolo-docs verlinkt die Datei als „full example“.
- **atoolo-docs veraltet:** alter Bundle-FQCN, `bin/console crawler:index`, kein `deny_endings`, `retry_status_codes` als Skalar, PHP-Beispiel ohne Kommas, `sp_max_teaser` mit 1.x-Bedeutung.
- **README:** keine Install-/Config-Schritte, kein SSRF-Hinweis, keine Extension Points (Tickets 03/15 „erledigt“, aber README nur um ein Wort geändert).

## Umsetzung
- Defaults für alle `atoolo.crawler.*`-Parameter im Bundle setzen (`build()` bzw. `prependExtension`); Host-`config/packages` überschreibt.
- CHANGELOG korrigieren und um Tickets 17–27 ergänzen; Abschnitt **„Upgrade von 1.x“** als Checkliste: `bundles.php`, Scheduler-Transport im Worker, Messenger-Routing-FQCN, Start-URLs, Schwelle beim ersten Lauf, Key-Umbenennungen (Ticket 24).
- Beispiel-Config neu schreiben (alle 2.0-Keys) und per Test durch die Factory schicken (Ticket 23).
- README: Installation, Master-Parameter, Key-Referenz (Ticket 24), Extension Points, Sicherheit (Ticket 20).
- atoolo-docs mit dem Release aktualisieren.

## Akzeptanzkriterien
- Host-Container ohne `atoolo_crawler_master.yaml` kompiliert.
- Beispiel-Config-Test grün.
- Jede Breaking/Behaviour-Änderung aus 17–27 steht im CHANGELOG.

## Dateien
`src/AtooloCrawlerTeaserIndexerBundle.php`, `CHANGELOG.md`, `README.md`, `config/example/exampleConfig.php`, neuer Test, atoolo-docs (extern)
