# 24 – `sp_*`-Schema für 2.x festlegen

**Typ:** Entscheidung + Verhalten · **Vor 2.0** (Schema ist ab dann öffentliche API) · zusammen mit 23 · Release-Review 2.0

## Hintergrund
Das Site-Config-Schema ist nach 2.0 eingefroren. Heute:

- **Gemischte Schreibweise:** `sp_introText_*` vs. `sp_datetime_*`.
- **Redundante Flags:** `sp_strip_query_params_active` neben der Liste; `sp_introText_present` / `sp_datetime_present` neben den Selektoren.
- **`sp_max_teaser` zählt geholte Seiten, nicht Teaser** (`URLCollector.php:71,81`): Start- und Zwischenseiten zählen mit, mit 1:N liefert eine Seite viele Teaser; Überschreitung um bis zu `parallel_requests - 1`. In 1.x zählte es gefundene Link-URLs.
- **`sp_datetime_only_date` wirkungslos:** `new DateTimeImmutable('2024-01-02')` ist schon Mitternacht (`Parser.php:408-419`).
- **Startseiten werden indiziert** (neu in 2.0, auch außerhalb `sp_allow_prefixes`, ohne robots-Check; E2E erwartet ein „Startseite“-Dokument). In 1.x nur zur Link-Suche.
- **1.x-Stringform** in `sp_start_urls` (siehe 23).
- `sp_title_*`: `present`/`requiredField` sind hart auf `true` (`PipelineConfig.php:169-170`).

## Entscheidungen (vor Umsetzung klären)
1. Keys vereinheitlichen (Vorschlag: snake_case, `sp_intro_text_*`) – mit Alias-Phase oder hartem Bruch?
2. Redundante Flags entfernen (leere Liste = aus)?
3. `sp_max_teaser` umbenennen in `sp_max_pages` oder auf Dokumente umstellen?
4. Startseite indizieren: ja / nein / Key `sp_index_start_urls`? Startseite durch `LinkFilter` + robots schicken?
5. `sp_datetime_only_date` entfernen oder korrekt machen (Uhrzeit abschneiden)?
6. Stringform bei `sp_start_urls` wieder erlauben?

## Umsetzung
Nach den Entscheidungen: Factory/Config (Ticket 23), Tests, Beispiel-Config und Key-Referenz (Ticket 28).

## Akzeptanzkriterien
- Entscheidungen im Ticket festgehalten.
- Key-Referenz (alle Keys, Typ, Default, Bedeutung) im README oder `docs/`.
- CHANGELOG listet jede Umbenennung als Breaking mit alt → neu.

## Dateien
`src/Config/*`, `src/Pipeline/Collector/URLCollector.php`, `src/Pipeline/Parser/Parser.php`, `config/example/exampleConfig.php`, `README.md`, `CHANGELOG.md`
