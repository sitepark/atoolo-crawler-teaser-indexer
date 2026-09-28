# 16 – Indizierungszeiten pro Kunde

**Typ:** Feature · **Unabhängig** von den anderen Tickets (kann jederzeit umgesetzt werden, baut auf 04 auf)

## Hintergrund
Die Cron-Zeiten stehen global in `atoolo.crawler.schedule` (`atoolo_crawler_master.yaml`). Das Commons-Projekt liefert diese YAML für alle Kunden aus – damit haben alle Kunden dieselben Indizierungszeiten, obwohl sie unterschiedliche brauchen können.

Betrieb: **eine Installation pro Kunde** (eigener Container, eigener Messenger-Worker, genau ein `ResourceChannel`). Die Site-Config liegt bereits pro Kunde vor: `IndexerConfigurationLoader` liest `resourceChannel->configDir/indexer/atooloTeaserCrawler.php`.

## Umsetzung
- Neuer optionaler Key auf oberster Ebene der Site-Config (neben `sp_crawling_sites`), z.B.:
  ```php
  return ['data' => [
      'sp_schedule' => ['30 2 * * *', '0 14 * * 1-5'],
      'sp_crawling_sites' => [ … ],
  ]];
  ```
- `Schedule::getSchedule()` liest die Zeiten über den `IndexerConfigurationLoader` aus der Kunden-Config; fehlt der Key oder die Datei, gilt `%atoolo.crawler.schedule%` aus der YAML als Fallback (Default für alle Kunden).
- Validierung aus Ticket 04 gilt für beide Quellen; die Fehlermeldung nennt die Quelle (Site-Config vs. YAML).
- Log-Meldung nennt, woher die Zeiten kommen.
- Hinweis dokumentieren: `getSchedule()` wird beim Start des Workers gelesen – nach einer Änderung von `sp_schedule` muss der Worker neu gestartet werden.
- Pro **Site** unterschiedliche Zeiten sind bewusst nicht Teil des Tickets (bräuchte eine Nachricht pro Site und einen Handler, der nur diese Site crawlt) – bei Bedarf eigenes Ticket.

## Akzeptanzkriterien
- Site-Config mit `sp_schedule` → deren Zeiten werden registriert, YAML ignoriert.
- Ohne `sp_schedule` bzw. ohne Datei → YAML-Zeiten.
- Ungültiger Ausdruck in `sp_schedule` → Exception mit Quelle und Ausdruck.
- `tests/ScheduleTest.php` erweitert.

## Dateien
`src/Messenger/Schedule.php`, `config/services.yaml`, `tests/ScheduleTest.php`, README/Doku (Ticket 15)
