# 05 – Truncation-Länge

**Proposal:** §9 · **Typ:** Bug (klein)

## Hintergrund
`Processor::truncate()` liefert `mb_substr($text, 0, $maxLength) . '…'` → Ergebnis ist `maxChars + 1` Zeichen lang. Spec: höchstens `maxChars` inkl. Ellipsis.

## Umsetzung
- `mb_substr($text, 0, max(0, $maxLength - 1)) . '…'`.
- Docblock korrigieren („maximum length of 120 characters" ist veraltet – Länge kommt pro Feld aus der Config).

## Akzeptanzkriterien
- `mb_strlen(result) <= maxChars` für Titel und Intro.
- Text genau `maxChars` lang → unverändert.
- `tests/ProcessorTest.php` angepasst.

## Dateien
`src/Pipeline/Processor/Processor.php`, `tests/ProcessorTest.php`
