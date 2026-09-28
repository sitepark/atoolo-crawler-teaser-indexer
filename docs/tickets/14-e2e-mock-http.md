# 14 – Echtes E2E mit MockHttpClient

**Proposal:** §11 · **Typ:** Test
**Voraussetzung:** 08–12 (sonst wird der Test mehrfach umgeschrieben)

## Hintergrund
`tests/CrawlerPipelineE2ETest.php` stubbt überwiegend die Steps – die Verdrahtung Collector → Parser → Processor → Indexer mit echten Implementierungen wird nicht getestet.

## Umsetzung
- Symfony `MockHttpClient` mit einer kleinen Fake-Site: Startseite mit Links (Tiefe 1), Artikelseiten mit OG-Tags, eine 404, eine 503 → Retry, `robots.txt` mit Disallow, Übersichtsseite für 1:N-Split.
- Echte Steps (aus dem Container oder manuell verdrahtet), nur `SolrIndexService`/Updater gestubbt und die übergebenen Dokumente geprüft.
- Assertions: richtige Anzahl/Felder der Dokumente, disallowte URL nicht gefetcht, jede URL genau einmal angefragt, Truncation, Relevance-Filter, Cleanup nur bei ausreichend Einträgen.

## Akzeptanzkriterien
- E2E-Test ohne gestubbte Steps, läuft in < 2 s ohne Netzwerk.

## Dateien
`tests/CrawlerPipelineE2ETest.php` (neu schreiben), ggf. `tests/Fixtures/`
