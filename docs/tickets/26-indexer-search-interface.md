# 26 – Indexer vom `\Atoolo\Search\Indexer`-Interface lösen, eigener Status

**Typ:** Aufräumen + Bug · **Vor 2.0** · klärt offenen Punkt aus proposal §13 · Release-Review 2.0

## Hintergrund
- **Interface ungenutzt:** search-bundle konsumiert `\Atoolo\Search\Indexer` nur über `IndexerCollection` (`!tagged_iterator { tag: 'atoolo_search.indexer' }`) für den Befehl `search:indexer`. Der Crawler-Indexer wird nirgends getaggt (weder auf `main` noch hier), es gibt kein Autoconfigure für das Interface. Die 7 No-op-Methoden (`src/Pipeline/Indexer/Indexer.php:188-237`) und ihre 7 Tests (`tests/IndexerTest.php:342-392`) sind tot. Docblock („RCE-based data sources“) und `getName()` = `'rce-indexer'` sind irreführend.
- **Status-Kollision:** Der Indexer bekommt `@atoolo_search.indexer.internal_resource_progress_state` (`config/services.yaml:72`) – dieselbe Instanz wie der `InternalResourceIndexer`, Status-Key `<index>-internal`. Jeder Crawl überschreibt den Status des CMS-Indexers im Backend; laufen beide gleichzeitig (Internal-Cron Default `0 2 * * *`), vermischen sie sich. Der Kommentar in `services.yaml:68` deutet das schon an.

## Umsetzung
- `implements \Atoolo\Search\Indexer` und die No-op-Methoden entfernen, Tests löschen, Docblock korrigieren.
- Eigenen `IndexerProgressState`-Service mit eigener Quelle (z.B. `crawler`) registrieren. Mit dem Owner des search-bundle abstimmen, ob der Status im Backend angezeigt werden soll (dann ggf. doch taggen – dann aber mit echter Implementierung).

## Akzeptanzkriterien
- Crawl ändert den Status `<index>-internal` nicht.
- `Indexer` implementiert nur `IndexerInterface`.
- CHANGELOG (Ticket 28): Breaking für Code, der den Crawler als `\Atoolo\Search\Indexer` typisiert.

## Dateien
`src/Pipeline/Indexer/Indexer.php`, `config/services.yaml`, `tests/IndexerTest.php`, `docs/proposal-next_major.md` (§13 abhaken)
