# 03 – User-Agent CRLF & SSRF-Hinweis

**Proposal:** §6 Sicherheits-Altlasten · **Typ:** Security

## Hintergrund
- `sp_user_agent` wird ungefiltert als Header gesetzt (`RequestExecutor`, Z. 28). `\r`/`\n` ermöglichen Header-Injection.
- SSRF: Ausgehende Requests haben keine Host-Allowlist; Links auf interne Hosts werden (sofern allow/deny es zulassen) gefetcht. Bisher nirgends dokumentiert.

## Umsetzung
- `PipelineConfig::userAgent()`: `\r`, `\n` (und `\0`) entfernen, trimmen; leer → Default.
- SSRF als bekanntes Risiko in README (Abschnitt Sicherheit/Konfiguration) beschreiben, inkl. Empfehlung `sp_allow_prefixes` auf die eigenen Hosts zu setzen. Keine Allowlist-Implementierung in diesem Ticket (die gehört ggf. in `LinkFilter`, Ticket 10).

## Akzeptanzkriterien
- User-Agent `"Foo\r\nX-Evil: 1"` → Header `FooX-Evil: 1` (keine Zeilenumbrüche).
- Test in `tests/CrawlerConfigTest.php` (bzw. PipelineConfig-Test).
- README-Abschnitt vorhanden.

## Dateien
`src/Config/PipelineConfig.php`, `README.md`, Tests
