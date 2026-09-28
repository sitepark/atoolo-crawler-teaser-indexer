# 10 – URLNormalizer aufteilen

**Proposal:** §5.2, §7.3 · **Typ:** Refactoring + Bugfix
**Voraussetzung:** 08

## Hintergrund
`URLNormalizer::normalize()` (352 Zeilen) mischt Kanonisierung und Filterung – und in falscher Reihenfolge: allow/deny wird **vor** dem Kanonisieren geprüft (Z. 48 vor 49). Ein nicht kanonischer Link (Großschreibung im Host, Default-Port, Query-Parameter, Fragment) kann dadurch einen Deny-Prefix umgehen oder fälschlich an einem Allow-Prefix scheitern. robots.txt wird zusätzlich separat im `URLCollector` gefiltert.

## Umsetzung
- `UrlCanonicalizer` (pure): parse + rebuild, konfigurierte Query-Parameter strippen, Fragmente strippen, Dedup (Reihenfolge erhalten).
- `LinkFilterInterface` + `LinkFilter`: allow/deny-Prefixes, allowed-path, unneeded, deny-endings **und** robots.txt – an einer Stelle, immer auf kanonischen URLs.
- `URLCollector`: `$this->linkFilter->filter($this->canonicalizer->canonicalize($links), $config)`; `findHrefUrlsByCssSelector` entsprechend vereinfachen.
- `LinkFilterInterface` als Service-Alias → Projekt-Decorator/Ersatz möglich (Naht 7.3). Optional hier die SSRF-Host-Allowlist andocken (s. Ticket 03).
- Tippfehler `filterDenyedAlowedUrls` verschwindet mit dem Umbau.

## Akzeptanzkriterien
- Link `https://EXAMPLE.com/intern/?utm=1#x` wird von Deny-Prefix `https://example.com/intern` erfasst.
- Bestehende Fälle aus `URLNormalizerTest` in `UrlCanonicalizerTest` / `LinkFilterTest` aufgeteilt und grün.
- `URLNormalizer` gelöscht.

## Dateien
`src/Pipeline/Collector/*`, `tests/URLNormalizerTest.php` → neue Tests, `tests/URLCollectorTest.php`
