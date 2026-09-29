# 29 – Datum strikt parsen

**Typ:** Bug · **Später** (2.x, nicht brechend) · Release-Review 2.0

## Hintergrund
`Parser` nutzt `new \DateTimeImmutable($raw)` (`src/Pipeline/Parser/Parser.php:~426`), das sehr großzügig ist. Reproduziert am 2026-09-29:
- Element enthält nur `2024` → `2026-09-29T20:24:00` (PHP liest „2024“ als Uhrzeit 20:24).
- `10:30` → heute 10:30.

Falsches Datum im Index; weil das Datum in die Dokument-ID eingeht (`Indexer::signature()`), ändert sich die ID täglich → jeder Lauf ersetzt das Dokument.

## Umsetzung
- Nur Werte akzeptieren, bei denen `date_parse()` Jahr, Monat und Tag ohne Fehler liefert, oder gegen eine Formatliste parsen (`!Y-m-d`, `!d.m.Y`, ATOM, RFC 2822, `Y-m-d\TH:i:sP`).
- Nicht parsbar → wie heute „kein Datum“ (bzw. Dokument verwerfen, wenn `sp_datetime_required_field`).
- Zeitzone explizit (Site-Default oder UTC); siehe auch Ticket 33 (TZ-abhängige Tests).

## Akzeptanzkriterien
- Tests: `2024`, `10:30`, `gestern` → kein Datum; `2024-01-02`, `02.01.2024`, ISO mit Offset → korrekt.

## Dateien
`src/Pipeline/Parser/Parser.php`, `tests/ParserTest.php`
