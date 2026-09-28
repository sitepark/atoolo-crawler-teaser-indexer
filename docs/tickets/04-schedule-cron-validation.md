# 04 – Cron-Ausdrücke früh validieren

**Proposal:** §8 Scheduler · **Typ:** Robustheit

## Hintergrund
`Schedule::getSchedule()` fängt jeden Fehler beim Anlegen der `RecurringMessage`s ab und loggt nur. Ein ungültiger Cron-Ausdruck führt dazu, dass (ggf. alle nachfolgenden) Schedules still fehlen – der Crawler läuft einfach nie. Außerdem lautet die Logmeldung „scheduled for %d sites", zählt aber Cron-Ausdrücke.

## Umsetzung
- try/catch entfernen; ungültiger Ausdruck → Exception (mit dem Ausdruck in der Meldung) propagiert, damit der Fehler beim Boot/`messenger:consume` sichtbar ist.
- Optional: Validierung bereits über `CronExpression::isValidExpression()` mit klarer `\InvalidArgumentException`.
- Logmeldung korrigieren („%d schedules registered").

## Akzeptanzkriterien
- Gültige Ausdrücke → wie bisher.
- Ungültiger Ausdruck → Exception, kein stilles Schlucken.
- `tests/ScheduleTest.php` angepasst.

## Dateien
`src/Messenger/Schedule.php`, `tests/ScheduleTest.php`
