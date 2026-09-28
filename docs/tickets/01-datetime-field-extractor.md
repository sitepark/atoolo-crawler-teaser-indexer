# 01 – Datetime-FieldExtractor wird nie gefragt

**Proposal:** §7.1 · **Typ:** Bug

## Hintergrund
`FieldExtractorInterface` definiert `FIELD_DATETIME` und verspricht, dass ein Extractor dafür ein `\DateTimeInterface` liefern darf. `Parser::extractFromBlock()` ruft für Titel und Intro `extractString()` → `extractCustom()` auf, für das Datum aber direkt `extractDateTime()` – registrierte Datetime-Extractors werden nie gefragt.

## Umsetzung
- In `extractFromBlock()` zuerst `extractCustom(FIELD_DATETIME, $source)` abfragen (analog `extractString()`, z.B. private `extractDateTimeCustom()`).
- Ergebnis `\DateTimeInterface` → als `\DateTimeImmutable` übernehmen (`DateTimeImmutable::createFromInterface`).
- Anderer Typ → `error` loggen (field/expected/actual wie in `extractString()`), Fallback auf eingebaute Extraktion.
- `requiredField`-Logik bleibt unverändert und gilt auch für den Custom-Wert.

## Akzeptanzkriterien
- Extractor mit `supports('datetime')` liefert Datum → landet im `ExtractedData`.
- Extractor liefert String/`null`/wirft → eingebaute Extraktion greift, Fehler wird geloggt (außer bei `null`).
- Tests in `tests/ParserFieldExtractorTest.php`.

## Dateien
`src/Pipeline/Parser/Parser.php`, `tests/ParserFieldExtractorTest.php`
