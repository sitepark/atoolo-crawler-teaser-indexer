# 22 – Redirects und `FetchedPage`

**Typ:** Bug + API · **Vor 2.0** (ändert die Step-Interfaces) · baut auf 19/20 auf · Release-Review 2.0

## Hintergrund
Nach einem Redirect ist `url` weiterhin die angefragte URL (`src/Pipeline/Fetcher/Fetcher.php:62-64`, Key aus `requestChunk`), nie `getInfo('url')`:

- Das Ziel wird unter der alten URL indiziert (abgelaufener Artikel `/news/123` → Startseite oder SSO-Seite eines fremden Hosts).
- Das Ziel läuft an `LinkFilter` und robots.txt vorbei.
- Relative Links werden gegen die falsche Basis aufgelöst (`URLCollector.php:167`): `/news` → 301 → `/news/`, Link `artikel-1.html` wird zu `https://ex.com/artikel-1.html` statt `…/news/artikel-1.html` (mit DomCrawler `Link` verifiziert).
- Das Ziel wird pro umleitender URL erneut geholt (visited kennt es nicht).

Die Seite ist heute ein rohes `array{url, html}` in drei Interfaces (`URLCollectorInterface`, `FetcherInterface`, `ParserInterface`). Ein weiteres Feld nachzurüsten bricht jeden Decorator und Test, der diese Arrays baut – deshalb jetzt.

## Umsetzung
- Final readonly Value Object `Dto\FetchedPage` (`url`, `finalUrl`, `html`, optional `contentType`); Interfaces darauf umstellen.
- Redirects selbst behandeln: `max_redirects: 0` am Crawler-Client (Ticket 20), 3xx-`Location` wie einen entdeckten Link behandeln (kanonisieren, `LinkFilter`, robots, visited). Alternative, falls einfacher: Client folgt Redirects, `finalUrl = getInfo('url')`, Seite verwerfen, wenn `finalUrl` den `LinkFilter` nicht passiert.
- Links gegen `finalUrl` auflösen; indiziert wird `finalUrl`.

## Akzeptanzkriterien
- E2E: `/alt` → 301 → `/neu`; indiziert wird `/neu`, einmal; relative Links auf `/neu` korrekt aufgelöst.
- Redirect auf verbotenen Prefix/Host → nicht indiziert.
- CHANGELOG (Ticket 28): Interface-Änderung für Decorators.

## Dateien
`src/Dto/FetchedPage.php` (neu), `src/Pipeline/Fetcher/*`, `src/Pipeline/Collector/URLCollector*.php`, `src/Pipeline/Parser/Parser*.php`, `src/Ports/RequestExecutor.php`, betroffene Tests, `tests/CrawlerPipelineE2ETest.php`
