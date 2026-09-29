# 33 – QS-Tooling: PHPUnit, Infection, CI-Matrix

**Typ:** Build · **Später** (2.x; Punkt 2 günstig vor dem Release) · Release-Review 2.0

## Hintergrund
Baseline (2026-09-29): `composer analyse` grün, 340 Tests grün (3× Zufallsreihenfolge), Coverage 95 % Zeilen, Infection-MSI 67 %.

1. **Infection läuft, ist aber ohne Wirkung und verrauscht.**
   - Die Infection-Ergebnisse sind nicht „falsch“; sie werden nur nie wirksam.
   - `composer test:infection … || exit 0` verschluckt jeden Fehlschlag, und es gibt kein `minMsi`.
   - ~100 der 331 überlebenden Mutanten ändern nur Log-Meldungen oder Log-Kontext (`ArrayItemRemoval`, `Concat`, `MethodCallRemoval` auf `$this->logger->…`).
   - Die übrigen sind echte Testlücken (→ Ticket 34, bzw. die Akzeptanzkriterien von 17/19).
2. **Zwei PHPUnit-Versionen.** `tools/phpunit.phar` (10.5.39, von Hand eingecheckt, nicht in `.phive/phars.xml`) nutzen CI und `infection.json:13`; vendor ist 10.5.64. Unter `TZ=Europe/Berlin` schlagen mit dem Phar 2 Tests fehl (`ParserTest::testExtractsTitleFromOgMeta`, `…FromH1IfNoMeta`, `+01:00` vs `+00:00`); vendor-bin erzwingt `date.timezone=UTC`, CI läuft in UTC – verdeckt.
3. **PHP-Versionen widersprüchlich.** Plattform 8.1; `phpcs.compatibilitycheck.xml` `testVersion 8.3-8.4`; CLAUDE.md 8.1–8.4; README-Badges 8.2–8.4; CI verify nur 8.1. phpcompatibility 9.3.5 kennt keine PHP-8-Syntax.
4. **PHPStan in CI nicht erzwungen:** die Reusable Workflows laufen mit `phpstan … || true`.
5. `e2e-test.yml` triggert atoolo-e2e-test, das dieses Bundle nicht einbindet → testet nichts.

## Umsetzung
- `infection.json`: `customPath` entfernen (vendor-phpunit), Logger-Aufrufe ignorieren (`"ignoreSourceCodeByRegex": ["\\$this->logger->.*"]`), `minMsi` auf aktuellen Stand setzen (nach Bereinigung messen) und in kleinen Schritten anheben; `|| exit 0` entfernen.
- `tools/phpunit.phar` löschen, überall vendor-phpunit (oder per phive verwalten).
- Tests setzen die Zeitzone explizit (`date_default_timezone_set` im Bootstrap oder `<ini name="date.timezone" value="UTC"/>` in `phpunit.xml`).
- `testVersion` auf `8.1-`, Badges und CLAUDE.md angleichen; CI-Matrix `8.1` + `8.4` (sofern die Reusable Workflows das erlauben).
- Mit dem Workflow-Owner klären: PHPStan-Fehler brechen den Build.
- e2e-Trigger entfernen oder das Bundle in atoolo-e2e-test einbinden.

## Akzeptanzkriterien
- `composer test:infection` scheitert unter `minMsi`; Log-Mutanten tauchen nicht mehr auf.
- `TZ=Europe/Berlin composer test` grün.

## Dateien
`infection.json`, `composer.json`, `phpunit.xml`, `tools/phpunit.phar`, `phpcs.compatibilitycheck.xml`, `README.md`, `CLAUDE.md`, `.github/workflows/*.yml`
