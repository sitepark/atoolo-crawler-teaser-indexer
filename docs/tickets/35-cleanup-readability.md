# 35 – Aufräumen: toter Code, Namen, CLAUDE.md

**Typ:** Lesbarkeit · **Später** (jederzeit, gut als Begleiter anderer Tickets) · Release-Review 2.0

## Hintergrund
Kein Verhalten betroffen, aber jeder, der neu reinschaut, stolpert:

- `Parser.php`: `:178` `if ($titleConfig->present)` ist immer wahr (`PipelineConfig.php:169-170` hart `true`); `:373` doppelte Null-Prüfung, beide Zweige `null`; `:104` `null ===` auf `splitHtmlDocumentSelector()`, das nie `null` liefert; `:183`/`:333` loggen „Title Not found **in Processor**“ aus dem Parser, `:333` doppelt; `:408` trimmt schon Getrimmtes; kein `declare(strict_types=1)`.
- `PipelineConfig.php:74`: `array_filter(..., 'is_string')` auf `list<string>`.
- `PipelineConfigHelper.php`: `elseif` bei `:287` ist eine Tautologie; `ctype_digit`-Zweig in `intList` von `is_numeric` überdeckt (das zudem `"1.5"` zu `1` abschneidet). Großteil entfällt mit Ticket 23.
- `Indexer.php:70/75` prüfen `introTextPresent()`/`dateTimePresent()` doppelt; `:76-86` `try/catch` um ein `setField`, das nicht wirft, plus `$dateValue = $date`; der Test `testDoIndexWithValidDateDoesNotLogWarning` testet diesen toten Code.
- `RelevanceEvaluator.php:60-87`: baut `$reasons`, die niemand liest (~20 überlebende Mutanten) – entweder loggen (debug) oder entfernen.
- Namen: `$rawextractedData` (Processor), `CrawlerConfigTest` (testet `PipelineConfig`).
- **CLAUDE.md-Drift:** Script-Namen `analyse:phpcsfixer`, `cs-fix`, `cs-fix:php-cs-fixer` existieren nicht (real: `analyse:php-cs-checker`, `php-cs-fixer`; `check` fehlt); Versionsuntergrenzen veraltet (6.4.36/1.14/10.5.63 vs. 6.4.42/1.14.2/10.5.64); Config-Ort einmal `<configDir>/indexer/…`, einmal `base_dir/indexer/…` (Code: `resourceChannel->configDir/indexer/atooloTeaserCrawler.php`); Verzeichnisbaum ohne `src/Kernel.php` (bzw. nach Ticket 21 korrekt ohne).

## Umsetzung
Punkte beheben; toten Code löschen statt kommentieren. CLAUDE.md und proposal §1 an den Stand nach 21–28 anpassen.

## Akzeptanzkriterien
- phpstan L9, cs-fixer, Tests grün; keine Verhaltensänderung.

## Dateien
`src/Pipeline/Parser/Parser.php`, `src/Config/PipelineConfig*.php`, `src/Pipeline/Indexer/Indexer.php`, `src/Pipeline/RelevanceEvaluator/RelevanceEvaluator.php`, `src/Pipeline/Processor/Processor.php`, `tests/CrawlerConfigTest.php`, `tests/IndexerTest.php`, `CLAUDE.md`, `docs/proposal-next_major.md`
