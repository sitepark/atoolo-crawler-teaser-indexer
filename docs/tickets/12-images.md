# 12 – Bilder extrahieren und indizieren

**Proposal:** §4.1, §7.2 · **Typ:** Feature · **Status:** später – erst bei Kundenanforderung umsetzen

## Hintergrund
Heute kann das Bundle nur die festen Felder Titel, Intro und Datum extrahieren und nach Solr schreiben. Kunden sollen künftig auch **Bilder** indizieren können.

## Anforderungen
- **Ein Bild besteht aus drei Teilen:** das Bild (URL), der Alternativtext und das Copyright. Die drei gehören zusammen und werden gemeinsam nach Solr indiziert.
- **Bilder sind optional.** Eine Site muss Bilder nicht konfigurieren, und ohne Konfiguration ändert sich nichts.
- **Pflichtangaben sind konfigurierbar.** Pro Site lässt sich festlegen, ob ein Bild nur dann übernommen wird, wenn
  - ein Copyright gefunden wurde (Pflicht oder nicht) und
  - ein Alternativtext gefunden wurde (Pflicht oder nicht).
- Die Konfiguration folgt dem bestehenden, einheitlichen `sp_*`-Schema (wie bei Titel, Intro und Datum mit `present`/`required_field`).

## Offen – Architekturentscheidung später
Wie Bilder durch die Pipeline kommen (Extraktion aus dem DOM, Transport bis zum Indexer, Schreiben der Solr-Felder), wird erst bei der Umsetzung entschieden. Dazu gehören auch:
- welche Solr-Felder die Bilddaten bekommen;
- wie relative Bild-URLs aufgelöst werden (die `FieldSource` kennt die Seiten-URL heute nicht);
- ob und wie mehrere Bilder pro Eintrag unterstützt werden.

## Akzeptanzkriterien
Werden bei der Umsetzung festgelegt.
