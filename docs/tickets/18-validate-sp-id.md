# 18 – `sp_id` validieren (Solr-Query)

**Typ:** Bug · **Blocker für 2.0** (verschärft die Validierung → nur im Major billig) · Release-Review 2.0

## Hintergrund
`sp_id` wird ungeprüft zur Solr-Quelle und landet roh in der Delete-Query von search-bundle:
`'-crawl_process_id:' . $processId . ' AND  sp_source:' . $source` (`vendor/atoolo/search-bundle/src/Service/Indexer/SolrIndexService.php:40`, aufgerufen aus `src/Pipeline/Indexer/Indexer.php:131`).
`PipelineConfigFactory::create()` prüft nur `is_string && !== ''` (`src/Config/PipelineConfigFactory.php:28-32`).

- `sp_id = '*'` → `sp_source:*` → löscht die Dokumente **aller** Quellen mit `sp_source`.
- Leerzeichen, `:`, `/`, Klammern → andere Treffer oder Query-Parse-Fehler, nachdem das Update schon gesendet wurde.

Die ID wird außerdem als `sp_objecttype` und im Dokument-Hash verwendet.

## Umsetzung
- In `PipelineConfigFactory::create()`: `sp_id` muss `/^[a-z0-9_-]+$/i` entsprechen, sonst `\InvalidArgumentException` (Site gilt als ungültig, wie heute bei fehlender ID).
- Fehlermeldung nennt den Wert.
- Vorher prüfen, ob bestehende Kunden-IDs das Muster erfüllen (Commons-Projekt durchsehen); ggf. Muster erweitern, aber keine Solr-Sonderzeichen zulassen.

## Akzeptanzkriterien
- `*`, `a b`, `a:b`, `(x)` → `InvalidArgumentException`.
- `stadt-ulm_news` → gültig.
- `tests/PipelineConfigFactoryTest.php` erweitert; CHANGELOG (Ticket 28) unter Breaking.

## Dateien
`src/Config/PipelineConfigFactory.php`, `tests/PipelineConfigFactoryTest.php`
