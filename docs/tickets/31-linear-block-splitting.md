# 31 – Block-Splitting linear machen

**Typ:** Performance · **Später** (2.x) · Release-Review 2.0

## Hintergrund
`Parser::resolveBlocks()` prüft für jeden Treffer von `sp_split_html_document` per `isAncestorOfAny` gegen alle anderen (`src/Pipeline/Parser/Parser.php:127,142`) – quadratisch. Gemessen: 1.000 `<article>` → 2,0 s, 4.000 (113 KB) → 42,8 s. Die 2-MB-Grenze erlaubt ~70.000 Blöcke. Eine große Archivseite (oder eine feindliche) blockiert den Lauf.

## Umsetzung
- Treffer per `getNodePath()` (oder `SplObjectStorage`) in ein Set legen und für jeden Knoten einmal die Vorfahren hochlaufen → O(n · Tiefe).

## Akzeptanzkriterien
- Bestehende Split-Tests grün (innerster Treffer gewinnt).
- 4.000 Blöcke < 1 s (Test mit großzügiger Grenze oder Benchmark-Notiz).

## Dateien
`src/Pipeline/Parser/Parser.php`, `tests/ParserTest.php`
