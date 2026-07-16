# Review

Ein paar einordnende Worte, bevor es in die Details geht. Der konkrete Umbauplan mit Signaturen und Entscheidungen steht im [proposal-next_major.md](proposal-next_major.md) — hier geht es nur um das *Warum* und darum, was ich am Bestehenden gut finde.

## Was gut ist (oder von der Idee her richtig)

- **Der Pipeline-Gedanke stimmt.** Den Ablauf als Kette von Schritten zu denken (crawlen → parsen → prozessieren → indizieren) ist genau die richtige Zerlegung. Der Umbau ändert daran nichts, er baut darauf auf.
- **Die Streaming-Absicht.** An mehreren Stellen sieht man, dass du mit Generatoren arbeiten wolltest. Bei potenziell tausenden Seiten ist das speicher­technisch goldrichtig. Das Memory-Verhalten ist dabei heute schon okay (Chunking + `unset` halten nie das HTML aller Seiten gleichzeitig) — nur die Generator-Laziness ist inkonsistent: eine Stelle streamt echt, beim `Processor` wirft `iterator_to_array` sie sofort weg, der `Fetcher` ist gar keiner. Der Umbau zieht die Idee sauber durch und spart nebenbei das doppelte Laden.
- **Getippte Config-Objekte.** `FieldExtractConfig`, `ContentScoringConfig` & Co. statt loser Arrays — guter Instinkt, den wir übernehmen.
- **Produktionsnähe.** Retry mit Backoff, robots.txt, Content-Scoring, `cleanupThreshold`, Messenger/Scheduler — das ist zu Ende gedacht und nicht bloß ein Happy-Path-Prototyp.
- **Es gibt Tests.** Eine ordentliche Suite, kein Alibi.

## Warum trotzdem umstrukturieren

Fast alle Punkte im Proposal lassen sich auf zwei wiederkehrende Muster zurückführen — es sind keine Einzelfehler, sondern zwei Grundentscheidungen, die sich fortpflanzen:

1. **Geteilter, veränderlicher Zustand statt expliziter Übergabe.** Die Config als Service über einen mutierbaren Context ist der Kern; daraus folgen das 3-Klassen-Konstrukt und eine latente Race Condition. Reicht man die Config stattdessen als immutables DTO durch die Pipeline, lösen sich mehrere Probleme auf einmal.
2. **Vereinheitlichung, die Unterschiede verdeckt.** Der `executeStep`-Wrapper behandelt alle Schritte gleich, obwohl „leeres Ergebnis" bei jedem Schritt etwas anderes bedeutet — im schlimmsten Fall wird der halbe Index gelöscht. Ein bisschen mehr Explizitheit pro Schritt kostet Zeilen, spart aber böse Überraschungen.

Dazu kommt Kosmetik mit realer Wirkung: Die Namensgebung (`Controller`, `Domain`) weckt Erwartungen, die der Code nicht einlöst. Das sind keine Bugs, aber es kostet jeden, der neu reinschaut, unnötig Orientierung.

## Fazit

Die Architektur, nach der du gegriffen hast, ist die richtige. Der Umbau vollendet die angefangenen Ideen — Streaming wirklich durchziehen, Daten typisieren, die Pipeline explizit machen — und entfernt zwei, drei Abkürzungen, die im Betrieb teuer werden. Für die konkreten Schritte geht es weiter im [proposal-next_major.md](proposal-next_major.md).
