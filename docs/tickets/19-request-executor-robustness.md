# 19 – RequestExecutor: Throttle, Fehler pro URL, Retry-After

**Typ:** Bug · **Blocker für 2.0** · Release-Review 2.0

## Hintergrund
Drei Fehler in `src/Ports/RequestExecutor.php`, alle per Skript reproduziert:

1. **`sp_delay_ms` wirkt beim Crawlen nicht.** Seiten laufen nur über `requestChunk()` (`:136`), das `throttle()` nie aufruft; gedrosselt wird nur `request()` (`:59`, nur für robots.txt). 4 Requests an einen Host mit `sp_delay_ms=1000`, `parallel=1` → 0,009 s. Folge: Kundenseiten werden ungebremst abgefragt, Rate-Limits/403 → füttert das Cleanup-Risiko aus Ticket 17. Die Throttle-Tests sterben nur, weil sie das öffentliche `throttle()` direkt aufrufen.
2. **Ein kaputter Link bricht die ganze Site ab.** `httpClient->request()` steht außerhalb des `try` (`:152`), gefangen wird nur `TransportExceptionInterface` beim Lesen. `<a href="https://s.test:99999/x">` oder `http://a:b:c/` → `InvalidArgumentException: Malformed URL` → `StepExecution[URLCollector]` → nichts wird indiziert, bei jedem Lauf, bis die fremde Seite ihren Tippfehler behebt.
3. **Retry-After ungedeckelt.** `(int) $retryAfter * 1000` (`:258`): `3600` lässt die ganze Welle (alle Hosts) eine Stunde schlafen und blockiert den Worker; Werte > ~9,2e15 werden zu float → `TypeError` wegen `int`-Rückgabetyp → Site-Abbruch. Alle Mutanten in `:255-258` überleben (ungetestet).

Nebenbei: `$waitMs = 200;` (`:100-102`) ist toter Code (gemeint war vermutlich `$backoffMs`).

## Umsetzung
- In `requestChunk()` vor jedem `request()` pro Host drosseln (Mindestabstand `delayMs` je Host, auch zwischen Retry-Wellen). `throttle()` private machen, über `requestChunk()` testen.
- `request()` je URL in `try/catch (\Throwable)`; loggen, URL verwerfen, Rest des Chunks läuft weiter. Zusätzlich im `UrlCanonicalizer` URLs verwerfen, die `parse_url` nicht zerlegen kann.
- Retry-After: `min((int) $v, MAX_RETRY_AFTER_S)` mit z.B. 120 s; darüber URL aufgeben. HTTP-Date-Format optional unterstützen.
- Toten `$waitMs`-Zweig korrigieren.

## Akzeptanzkriterien
- Test: 3 URLs eines Hosts, `delayMs=100` → Gesamtdauer ≥ 200 ms (mit injizierbarer Uhr/Sleeper, keine echten Sleeps im Test).
- Test: Chunk mit einer malformed URL liefert die übrigen Responses.
- Test: `Retry-After: 99999999999999999999` und `3600` → gedeckelt bzw. aufgegeben, kein TypeError.
- E2E (Ticket 34): Seite mit kaputtem Link wird trotzdem indiziert.

## Dateien
`src/Ports/RequestExecutor.php`, `src/Ports/RequestExecutorInterface.php`, `src/Pipeline/Collector/UrlCanonicalizer.php`, `tests/RequestExecutorTest.php`
