# 11 – RelevanceEvaluator typisieren

**Proposal:** §5.1 · **Typ:** Refactoring + Bugfix
**Voraussetzung:** 08

## Hintergrund
- Interface nimmt ein loses `array<string,mixed>`.
- Key `html` enthält bei gesetztem `sp_relevance_content_selector` **Text** (`FieldSource::text()`), sonst `$crawler->outerHtml()` – also **Markup**. Folge: Keywords matchen Tag-/Attributnamen/Skripte, `bodyTextLengthLt` zählt Markup mit.
- Nur ein Selektor statt der Prioritätsliste aus dem Proposal.
- Klassen-Kommentar (Aufruf aus dem Parser) fehlt.

## Umsetzung
- Signatur: `relevant(ExtractedDataInterface $entry, FieldSource $source, PipelineConfig|ContentScoringConfig $config): bool`. `FieldSource` statt `Crawler` → kein DOM-Leak (gleiches Argument wie bei 7.1).
- Region-Wahl im Evaluator: erster nicht-leerer sichtbarer Text (`FieldSource::visibleText()`) aus der konfigurierten Selektor-Liste; keine Defaults – ohne (passenden) Selektor wird der ganze Block bewertet.
- `sp_relevance_content_selector` ist eine Liste wie alle Listen-Keys (einheitliches Schema), Config-Feld `ContentScoringConfig::$contentSelectors`.
- Parser übergibt nur noch Entry + Source; `ExtractedData` wird für den Evaluator vor der Relevanzprüfung gebaut.
- Klassen-Kommentar: „wird pragmatisch aus dem Parser aufgerufen, arbeitet auf geparstem DOM, keine erneute HTML-Analyse".

## Akzeptanzkriterien
- Kein Markup mehr im Haystack (Test: Keyword nur in einem `class`-Attribut → kein Treffer).
- Selektor-Priorität getestet (erster Treffer gewinnt, Fallback `body`).
- `tests/RelevanceEvaluatorTest.php`, `tests/ParserTest.php` angepasst.

## Dateien
`src/Pipeline/RelevanceEvaluator/*`, `src/Pipeline/Parser/Parser.php`, `src/Config/ContentScoringConfig.php`, `src/Config/PipelineConfig.php`, Tests
