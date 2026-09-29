# 23 – Site-Config streng validieren

**Typ:** Verhalten · **Vor 2.0** (verschärft akzeptierte Eingaben) · Release-Review 2.0

## Hintergrund
`PipelineConfigHelper` loggt ungültige Werte und nimmt den Default; der Docblock der Factory behauptet „validated once here“, validiert wird aber nur `sp_id`. Bei handgeschriebenen Kunden-Configs macht das Tippfehler zu stillen Verhaltensänderungen – der Cleanup-Schutz (Ticket 17) greift nur bei leerem, nicht bei falschem Ergebnis.

Reproduziert (Factory, 0 Logzeilen):
- `sp_introtext_present` (Tippfehler) → ignoriert, keine Intros.
- `sp_start_urls => ['https://ex.com/']` (**1.x-Stringform**) → still verworfen (`PipelineConfigHelper.php:210-222`), `startUrls()` = `[]` → irreführende `ThresholdNotMetException`.
- `'sp_title_max_chars' => '80 '`, `'sp_content_scoring_active' => 1` → Defaults.
- Keyed Rule-Liste (`'short' => [...]`) → `TypeError` in `PipelineConfigHelper.php:325` (`int $index` bekommt String-Key).
- Accessors parsen das Roharray bei jedem Aufruf neu: eine Seite mit 201 Blöcken → **2.010 Logzeilen**, dieselbe Meldung 402×.
- Start-URL-Tiefe: fehlend → 0, nicht numerisch → 1 (`:216-219`).
- `sp_cleanup_threshold` darf negativ sein.

## Umsetzung
- Alles einmal in `PipelineConfigFactory::create()` parsen und in `PipelineConfig` als fertige Werte ablegen; Accessors lesen nur noch.
- Unbekannte `sp_*`-Keys und falsche Typen → `\InvalidArgumentException` mit Key und Wert (Site ungültig, wie fehlende `sp_id`). Nicht-`sp_*`-Keys bleiben für Projekte frei (siehe Ticket 25, `extra()`).
- 1.x-Stringform in `sp_start_urls` wieder akzeptieren (Tiefe 0) – oder klar ablehnen; Entscheidung in Ticket 24.
- Wertebereiche: Zahlen ≥ 0 bzw. ≥ 1 wo sinnvoll (`sp_parallel_requests`, `sp_max_retry`, `sp_cleanup_threshold`).
- Ohne `sp_*`-Schema-Entscheidung aus Ticket 24 nicht starten – beide zusammen umsetzen.

## Akzeptanzkriterien
- Jeder der obigen Fälle → Exception bzw. korrekter Wert, mit Test.
- Ein Parse-Lauf erzeugt pro Fehler genau eine Meldung.
- Die Beispiel-Config (Ticket 28) wird in einem Test durch die Factory geschickt.

## Dateien
`src/Config/PipelineConfigFactory.php`, `src/Config/PipelineConfig.php`, `src/Config/PipelineConfigHelper.php`, `tests/PipelineConfigFactoryTest.php`, `tests/PipelineConfigHelperTest.php`, `tests/CrawlerConfigTest.php`
