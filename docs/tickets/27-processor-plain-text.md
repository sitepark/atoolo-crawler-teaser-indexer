# 27 – Processor: Felder als Klartext behandeln

**Typ:** Bug (Datenverlust + Markup-Durchlass) · **Vor 2.0** (Verhaltensänderung) · Release-Review 2.0

## Hintergrund
`Processor::cleanString()` (`src/Pipeline/Processor/Processor.php:77-90`) macht `strip_tags` **dann** `html_entity_decode`. Die Werte kommen aber aus DOM-`text()` / `getAttribute()` / `meta()` und sind bereits dekodierter Klartext. Folge (reproduziert mit echtem Parser + Processor):

| Quelle | Indiziert |
|---|---|
| `<h1>Kinder &lt;6 Jahre frei</h1>` | `Kinder` |
| `<p>Preis &lt;10€ …</p>` | `Preis` |
| `<p>&lt;b onclick=x Rest …</p>` | `""` |
| `<h1>&amp;lt;img src=x onerror=alert(1)&amp;gt;</h1>` | `<img src=x onerror=alert(1)>` |
| intro `&amp;lt;a href=&amp;quot;javascript:…&amp;quot;&amp;gt;` | `<a href="javascript:…">klick</a>` |

Also: legitimer Text geht verloren, doppelt kodiertes Markup kommt als echtes Markup im Index an. Ausnutzbar als Stored XSS nur, wenn das Teaser-Rendering nicht escaped – das ist im Frontend zu prüfen. Der Datenverlust passiert heute.

Die bestehenden `ProcessorTest`-Eingaben (`'<p>Hello <b>World</b></p>'`) entstehen im echten Parser nie.

## Umsetzung
- Felder als Klartext behandeln: kein `strip_tags`, kein zweites Dekodieren; nur Whitespace normalisieren und kürzen.
- Falls ein `FieldExtractor` HTML liefert: dokumentieren, dass Extractoren Klartext zurückgeben (oder dort gezielt strippen).
- Doku: Ausgabe muss beim Rendern escaped werden. Frontend-Rendering der Teaser prüfen.
- Docblock „guarantees safe“ (`:20`) korrigieren.

## Akzeptanzkriterien
- Pipeline-Test (Parser + Processor) mit allen Zeilen der Tabelle: legitimer Text bleibt vollständig (`Kinder <6 Jahre frei`); doppelt kodiertes Markup bleibt als der Text, den der Browser anzeigt (`&lt;img …&gt;`), und wird nicht zu `<img …>` dekodiert.
- `ProcessorTest` nutzt realistische Eingaben.

## Dateien
`src/Pipeline/Processor/Processor.php`, `src/Pipeline/Parser/FieldExtractorInterface.php` (Doku), `tests/ProcessorTest.php`, `tests/CrawlerPipelineE2ETest.php`
