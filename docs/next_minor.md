# Minor Release Scope

Abwärtskompatible Fixes mit dem Ziel, den Produktionsbetrieb abzusichern. Keine Architekturänderungen — diese kommen in den Major Release.

### Tippfehler

1. `RequestExecutor.php:141` ruft `$this->config->delayMS()` auf. In `CrawlerConfig` existiert nur `delayMs` (kleines s ist korrekt)
2. `Indexer.php:60` ruft `$this->config->dateTimePresent()` auf. In `CrawlerConfig` existiert nur `datetimePresent` (Großes T wäre korrekt)

**Fix:** 
1. `RequestExecutor.php:141`: `$this->config->delayMs()` statt `$this->config->delayMS()`
2. `CrawlerConfig.php:182`: `dateTimePresent` statt `datetimePresent`. (Notiz hierzu: Da wir hier den Methodennamen verändern, ist das *eigentlich* ein Breaking-Change. Angenommen irgendein Dev da draußen benutzt das Bundle in der Version 1.0.0 und benutzt `CrawlerConfig::datetimePresent`, dann würde es beim Update auf 1.1.0 krachen. Ist in der Praxis aber natürlich egal, da bisher mit Sicherheit noch keiner das Bundle nutzt. Also einfach ändern.)

### `executeStep` schluckt Exceptions und returnt dann leere Arrays

`CrawlerManager::executeStep` fängt alle `Throwable`s und gibt einen leeren Iterator zurück. Dieses Leer-Ergebnis wird dann bis zum Indexer weitergereicht, der daraufhin alle zuvor indizierten Dokumente löscht. 

**Fix:** 
- Wenn ein Step in `executeStep` einen Fehler wirft, sollte das Programm direkt abbrechen.
- Bei anderen Indexern haben wir mit einem `cleanupThreshold`-Parameter im Indexer gearbeitet, siehe [hier](https://github.com/sitepark/atoolo-search-bundle/blob/a74bb0fe0aae1d5ec752930d5ab73db08d53757f/src/Service/Indexer/SolrXmlIndexer.php#L100). Dieser bestimmt, welche Mindest-Anzahl an Dokumenten bei einem Durchlauf indiziert werden muss, bevor die alten Einträge gelöscht werden. Ich denke, das sollten wir hier auch so machen.

### Falsche Flag für Pflichtfeld-Check in `Parser::extractTeasers` 

- `Parser.php:68`: `if ($introConfig->present) { ...` sollte eher `$introConfig->requiredField` checken, nicht? `present` steht dafür, ob die Extraktion überhaupt aktiviert ist, wenn ich das korrekt verstehe.
- `Parser.php:77`: Gleiche Fehler bei `$dateTimeConfig->present`

**Fix:** 
`$introConfig->requiredField` statt `$introConfig->present` & `$dateTimeConfig->requiredField` statt `$dateTimeConfig->present` checken

### Invertierte Logik für `forcedArticleUrls` in `TeaserRelevanceEvaluator` korrigieren
`TeaserRelevanceEvaluator.php:33–37` gibt `false` (nicht relevant) für URLs zurück, die in `forcedArticleUrls` stehen. Der Name impliziert, dass diese URLs immer eingeschlossen werden sollen.

**Fix:** 
`true` statt `false` zurückgeben, wenn URL in `forcedArticleUrls` ist

### Doppeltes `truncate` in `Parser` und `Processor`

`Parser::truncate` kürzt den Text auf eine konfigurierbare Anzahl an Zeichen. Später wird der Text dann nochmals in `Processor::truncate` fest auf 120 Zeichen gekürzt. Das ist nicht nur doppelte Logik, sondern auch unerwartet, weil eine konfigurierte Maximallänge von 200 Zeichen vom `Processor` einfach ignoriert wird. 

**Fix:** 
- Das Kürzen der Texte sollte nur ein Feature vom `Processor` sein. Also `Parser::truncate` streichen. Stattdessen in `Processor::truncate` die konfigurierte Länge auslesen und ggfs. anwenden. 
- Side note: Die Zeile `mb_substr($text, 0, $maxLength) . '...'` in `Processor::truncate` ist nicht ganz korrekt
    1. Hier sollte statt "..." das [Ellipsis-Zeichen](https://en.wikipedia.org/wiki/Ellipsis) (…) genutzt werden.
    2. Bei `$maxLength` muss hier noch minus 1 gerechnet werden, da der String sonst ein Zeichen zu lang ist, also `mb_substr($text, 0, $maxLength - 1) . '…'`

### Property `$source` in `Indexer` leer initialisieren

`$source` in `Indexer` sollte zumindest mit einem Leer-String `''` initialisiert werden, damit ein falsch getimeter Aufruf von `getSource` keinen fatalen Fehler wirft. 

**Fix:** 
`private string $source = '';`

### Kaputte phpdoc-Kommentar in `URLCollector`

`URLCollector::findHrefUrlsByCssSelector` und `URLCollector::crawlByDepth` haben zwei `@return`-Annotations

### Fehlende oder leere `sp_id` in `CrawlSiteRunner` validieren

`CrawlSiteRunner.php:26`: `$siteKey = $site['sp_id'] ?? null`. Eine leere `sp_id` sollte einen Fehler werfen und das Programm direkt abbrechen.

**Fix:**
- `\InvalidArgumentException` werfen wenn `sp_id` leer/null ist.

### Zu viele Warning-Logs in `CrawlerConfigHelper::string` usw.

`CrawlerConfigHelper::string` loggt alle nicht-vorhandenen Config-Keys als Warning. Das sollte eher ein Debug-Log sein. Gleiches gilt für `CrawlerConfigHelper::int`, `CrawlerConfigHelper::bool` usw.

### `backoffMs` in `RequestExecutor::request` sollte standardmäßig nicht 0 sein

Derzeit steht dort `$backoffMs = $this->config->delayMs()`. Die Config-Option `delayMs()` ist standardmäßig 0. Beim späteren `$backoffMs *= 2` würde `$backoffMs` also stets 0 bleiben, was kontraproduktiv ist.

**Fix:**
- `$backoffMs` mit einem Standard-Wert von 500 (o.Ä.) initialisieren, wenn `delayMs()` 0 ist: `$backoffMs = max(500, $this->config->delayMs());`