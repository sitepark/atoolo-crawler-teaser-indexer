# Architektur 2.0 (Next Major)

## Wie dieses Dokument zu lesen ist

Dieses Dokument beschreibt die **umgesetzte Architektur** des Majors 2.0 und die Entscheidungen dahinter. Warum umgebaut wurde – und was am Bestehenden gut war – steht in [review.md](review.md). Die Umsetzung ist in [tickets/](tickets/README.md) nachvollziehbar; offene Punkte stehen am Ende (Abschnitt 13).

Ziele (ISO 9126): **Lesbarkeit, Erweiterbarkeit, Austauschbarkeit**. Das Bundle wird über ein **Commons-Projekt** genutzt, auf das nach und nach ältere Kundenprojekte umgestellt werden (eine Installation pro Kunde).

Grundsatzentscheidungen:
1. **Namespace `Atoolo\CrawlerIndexer`** (statt `Atoolo\Crawler`). „teaser“ ist im Kern überflüssig – das Bundle crawlt, prozessiert, indiziert.
2. **Der Indexer bleibt direkt an Solr gekoppelt** – das Bundle hat genau einen Zweck, ein Port wäre Overhead (Abschnitt 9).
3. **Die vier Steps sind fest** – keine generische ETL-Engine; angepasst wird per Decorator oder an gezielten Nähten (Abschnitt 10).

---

## 1. Verzeichnisstruktur

```
src/
├── Application/        SitesRunner, SitesRunResult, PipelineRunner
├── Command/            PipelineCommand
├── Config/             PipelineConfig, PipelineConfigFactory, PipelineConfigHelper
│                       + Value-Objects (Title/Intro/DateTimeExtractConfig, ContentScoring(Rule)Config, LengthConditionConfig)
├── Dto/                ExtractedData(Interface)
├── Exception/          StepExecution, IndexingErrorsException, ThresholdNotMetException
├── Messenger/          Schedule, StartPipelineMessage(Handler)
├── Pipeline/
│   ├── CrawlerPipeline.php
│   ├── Collector/          URLCollector, UrlCanonicalizer, LinkFilter(Interface), RobotsTxtChecker(Interface)
│   ├── Fetcher/            Fetcher(Interface)
│   ├── Parser/             Parser(Interface), FieldExtractorInterface, FieldSource
│   ├── RelevanceEvaluator/ RelevanceEvaluator(Interface)
│   ├── Processor/          Processor(Interface)
│   └── Indexer/            Indexer(Interface)
├── Ports/              RequestExecutor(Interface)
└── AtooloCrawlerTeaserIndexerBundle.php
```

Bewusst beibehalten: Runner in `Application/`, Exceptions in `Exception/`, `Ports/`. Klassennamen wurden dort angepasst, wo sie das neue Modell besser beschreiben (`CrawlerManager` → `CrawlerPipeline`, `CrawlerConfig` → `PipelineConfig`, `TeaserRelevanceEvaluator` → `RelevanceEvaluator`, `Index` → `PipelineCommand`, `StartCrawlerMessage` → `StartPipelineMessage`). Entfernt: `Controller/`, `Domain/`, `Console/`, `CrawlerConfigContext`.

## 2. Pipeline

`CrawlerPipeline::run(PipelineConfig $config)` verkettet die Steps **lazy**; die Arbeit passiert, während der Indexer die Kette konsumiert:

```php
$this->indexer->prepare('Crawler run started');   // Laufzeit zählt ab Crawl-Beginn

$pageChunks = $this->collect($config);
$documents  = $this->parse($pageChunks, $config);
$processed  = $this->process($documents, $config);

$this->index($processed, $config);
```

| Interface | Methode |
|-----------|---------|
| `URLCollectorInterface` | `collect(PipelineConfig): iterable<list<array{url, html}>>` |
| `ParserInterface` | `extractData(array $pageChunk, PipelineConfig): Generator<ExtractedDataInterface>` |
| `ProcessorInterface` | `sanitizeText(iterable, PipelineConfig): iterable<ExtractedDataInterface>` |
| `IndexerInterface` | `prepare(string)`, `doIndex(iterable, PipelineConfig): IndexerStatus` |

**Speicher:** Das HTML ist durch die Chunks (`sp_parallel_requests`) begrenzt, die Pipeline hält keine Dokumentliste. Einzige Pufferstelle ist der Indexer: `progressHandler->start()` braucht die Gesamtzahl vorab, und `SolrIndexUpdater` sammelt ohnehin alle Dokumente bis `update()`. Vollständiges Streaming lohnt erst, wenn Solr blockweise aktualisiert wird.

**Fehler-Policy:**
- Per-Item-Fehler (eine Seite, ein Block, ein Dokument) → loggen und überspringen, innerhalb der Steps.
- Step-Fehler → jeder Step hat einen eigenen Guard und wirft `StepExecution` mit Step-Namen. Weil die Kette lazy ist, läuft ein Upstream-Fehler durch die späteren Guards; eine bereits benannte `StepExecution` wird unverändert durchgereicht.
- Indexer meldet Fehler → `IndexingErrorsException`; die Site gilt als fehlgeschlagen, der Command endet mit Exit-Code 1.
- Infrastruktur (Solr nicht erreichbar, Config-Datei fehlt) → propagiert.

## 3. Config

`PipelineConfig` ist ein **immutables DTO pro Site**, erzeugt von `PipelineConfigFactory::create(array $siteData)`. Ungültige Config (fehlende oder leere `sp_id`) → `\InvalidArgumentException`; die Site-Schleife fängt das pro Site ab.

Die Config wird **als letzter Parameter jedes Step-Aufrufs** übergeben, nicht in Konstruktoren gespeichert. Damit sind alle Steps zustandslose Singleton-Services (Voraussetzung für Decorators, Abschnitt 10), und es gibt keinen geteilten, veränderlichen Config-Zustand mehr.

**Einheitliches Schema:** alle Site-Keys `sp_*`, Listen sind immer Listen (auch `sp_relevance_content_selector`). Bundle-weite Parameter in `atoolo_crawler_master.yaml`:
- `atoolo.crawler.schedule` – Cron-Ausdrücke; ungültige Ausdrücke brechen beim Start des Schedulers ab.
- `atoolo.crawler.retry_status_codes` – HTTP-Status, die wiederholt werden.
- `atoolo.crawler.deny_endings` – URL-Endungen, denen nie gefolgt wird (zusätzlich zu `sp_deny_endings`).

## 4. Daten zwischen den Steps

- Collector → Parser: Chunks von `array{url, html}`.
- Parser → Processor → Indexer: `ExtractedData` (`url`, `title`, `?introText`, `?date`), immutable.

**1:N:** Eine Seite darf mehrere Einträge erzeugen. `sp_split_html_document` (XPath-Liste) zerlegt die Seite in Blöcke; jeder Block wird unabhängig geparst. Ein Treffer, der ein anderer Treffer umschließt, wird verworfen (der innerste gewinnt). Ohne Treffer ist die ganze Seite ein Block.

## 5. Collector (Crawlen und Fetchen)

`URLCollector` crawlt breadth-first ab den Start-URLs und **lädt jede Seite genau einmal** – URL-Suche und Fetchen sind zusammengelegt (früher lud der Collector fast alle Seiten zur Link-Suche, der Fetcher danach erneut). Jede Ebene wird in Chunks von `sp_parallel_requests` parallel geladen und direkt weitergereicht; `sp_forced_article_urls` werden immer geladen, `sp_max_teaser` begrenzt den Crawl.

Gefundene Links durchlaufen `LinkFilter::filter(UrlCanonicalizer::canonicalize($links))`:
- **`UrlCanonicalizer`** schreibt nur um: Schema und Host klein, kein Default-Port, konfigurierte Query-Parameter (`sp_strip_query_params`) und Fragmente (`sp_strip_fragments`) entfernt, dedupliziert. Auch die Start-URL wird kanonisiert, damit jede Schreibweise einer Seite nur einmal geladen wird.
- **`LinkFilter`** entscheidet, was verfolgt wird – alle Regeln an einer Stelle, immer auf kanonischen URLs: allow-/deny-Prefixe, deny-Endungen, robots.txt (`sp_respect_robots_txt`). Prefixe deshalb in kanonischer Form konfigurieren. Früher wurde teils vor dem Kanonisieren und doppelt gefiltert.
- Verfolgt werden `http://` und `https://`.

**HTTP (`RequestExecutor`):** Chunks werden gleichzeitig abgefeuert; Retries für Transportfehler und `retry_status_codes` laufen wellenweise mit exponentiellem Backoff (`sp_backoff_ms`, `sp_max_retry`) und respektieren `Retry-After`. Throttling pro Host (`sp_delay_ms`). Der User-Agent (`sp_user_agent`) wird pro Request gesendet.

**Zustand eines Laufs:** Throttle-Zeitstempel und robots.txt-Cache implementieren `ResetInterface`; Symfony setzt sie per `kernel.reset` nach jeder Messenger-Nachricht (= ein Lauf über alle Sites) zurück. Innerhalb eines Laufs teilen sich die Sites diesen Zustand bewusst.

## 6. Parser

Extrahiert pro Block Titel, Intro und Datum: OpenGraph zuerst, dann CSS (Datum: `datetime`-Attribut vor Text; `sp_datetime_only_date`). Pflichtfelder (`required_field`) verwerfen den Block, wenn sie fehlen; der Titel ist immer Pflicht, `sp_title_prefix` wird vorangestellt. Seiten über 2 MB HTML werden übersprungen.

**`FieldSource`** ist der Lesezugriff auf den Block (`text()`, `attr()`, `meta()`, `visibleText()`). Sie gibt nie den `Crawler` heraus – ein gehaltener DOM-Verweis würde die ganze Seite im Speicher halten.

**Relevanz (`RelevanceEvaluator`):** optional (`sp_content_scoring_active`). Positive/negative Regeln auf Titel, Intro und Hauptinhalt; `sp_forced_article_urls` sind immer relevant; Fragment-URLs −2. Der Hauptinhalt ist der **sichtbare Text** (ohne `script`, `style`, `noscript`, `template`) des ersten Treffers aus `sp_relevance_content_selector`, ohne Treffer der ganze Block. Welche Region zählt, ist eine Scoring-Entscheidung – deshalb wählt sie der Evaluator, auf der bereits geparsten `FieldSource`.

## 7. Processor

Entfernt HTML, Scripts und Styles, dekodiert Entities, normalisiert Whitespace. **Kürzung nur hier**, nach dem Säubern, auf höchstens `maxChars` Zeichen **inklusive** `…`. Leere Titel werden verworfen.

## 8. Wiring & Einstiegspunkte

- `SitesRunner` lädt die Site-Liste (`atooloTeaserCrawler`) und crawlt jede Site; eine fehlschlagende Site bricht die übrigen nicht ab. Ergebnis: `SitesRunResult` (fehlgeschlagene und ungültige Sites).
- `PipelineRunner`: eine Site – `PipelineConfigFactory` → `CrawlerPipeline::run()`.
- `PipelineCommand` (`crawler:scheduler-atoolo-crawler-teaser-indexer`) und `StartPipelineMessageHandler` sind dünne Wrapper um `SitesRunner` (Exit-Code bzw. Messenger-Retry bei Config-Fehlern).
- `Schedule` registriert pro Cron-Ausdruck eine `RecurringMessage`.
- `services.yaml`: alle Steps sind Services, jedes Step-Interface ist auf seine Default-Implementierung gealiast.

## 9. Indexer

Hängt bewusst direkt an `SolrIndexService`. Dedupliziert nach Inhalt (Titel + Intro + Datum); die Dokument-ID ist ein Hash aus Source und Inhalt (nicht mehr die URL), damit eine Seite mehrere Dokumente liefern kann. Die Source (`sp_id`) ist eine lokale Variable – der Indexer wird von allen Sites geteilt.

**Cleanup:** Alte Dokumente der Source werden nur gelöscht (`deleteExcludingProcessId`), wenn mehr als `sp_cleanup_threshold` Dokumente erfolgreich indiziert wurden; sonst `ThresholdNotMetException`. So leert ein stiller Fehler nicht den Index. Gewählt wurde die tolerante Threshold-Logik statt „alles oder nichts“.

## 10. Erweiterbarkeit

**Steps per Decorator austauschen.** Jeder Step ist ein Service hinter seinem Interface:

```php
#[AsDecorator(ParserInterface::class)]
final class MyParser implements ParserInterface
{
    public function __construct(#[AutowireDecorated] private ParserInterface $inner) {}

    public function extractData(array $htmlData, PipelineConfig $config): \Generator
    {
        foreach ($this->inner->extractData($htmlData, $config) as $entry) {
            yield $entry; // vorher/nachher eingreifen
        }
    }
}
```

Decorators desselben Steps stapeln sich (`decoration_priority`) – Commons-Projekt und Kundenprojekt können denselben Step anpassen, ohne voneinander zu wissen.

*Verworfen:* eine Factory pro Step (doppelte Anzahl Interfaces; für innere Bausteine eine Kette von Factories) und eine Factory mit `protected createX()`-Methoden (Anpassung per Vererbung; bei Commons + Kunde nur als Vererbungskette möglich, die ein Kunde leicht falsch aufsetzt).

**Gezielte Nähte** für die häufigen Fälle:
- **`FieldExtractorInterface`** – ersetzt die Extraktion von Titel, Intro oder Datum (z.B. JSON-LD, deutsche Monatsnamen). Registrierter Service genügt (Autoconfiguration). Liefert ein Extractor `null`, einen falschen Typ oder wirft er, übernimmt die eingebaute Extraktion. Er kann nur bestehende Felder ersetzen, keine neuen einführen.
- **`LinkFilterInterface`** – Regeln fürs Verfolgen von Links, z.B. eine Host-Allowlist.

**Bewusst geschlossen:** Solr-Kopplung nicht generalisieren; die vier Steps bleiben fest.

## 11. Sicherheit

- **XPath-Injection** in `meta()`: behoben – der Property-Name wird in PHP verglichen, nicht in den Ausdruck eingesetzt.
- **Header-Injection** über `sp_user_agent`: behoben – CR, LF und NUL werden entfernt.
- **SSRF (Risiko, nicht behoben):** Gefundenen Links wird ohne Host-Allowlist gefolgt, auch über Redirects. Eine gecrawlte Seite kann den Crawler so auf interne Adressen lenken. Gegenmaßnahme: `sp_allow_prefixes` immer auf die Hosts der Site setzen; bei Bedarf eine Allowlist per `LinkFilterInterface`-Decorator.

## 12. Tests

- Unit-Tests pro Step und Baustein.
- `CrawlerPipelineTest` – Orchestrierung mit gestubbten Steps (lazy Kette, Step-Namen bei Fehlern).
- `CrawlerPipelineE2ETest` – alle echten Steps gegen eine Fake-Site (`MockHttpClient`), nur Solr gestubbt: Link-Suche, Filter, robots.txt, 404/503-Retry, Extraktion, Kürzung, 1:N, exakte Liste der HTTP-Anfragen.
- `StepDecorationTest` – lädt die echte `services.yaml` und prüft gestapelte Decorators.

## 13. Offen / später

- **Bilder** (Bild, Alternativtext, Copyright; Pflichtangaben konfigurierbar) – erst bei Kundenanforderung, Architektur dann ([Ticket 12](tickets/12-images.md)).
- **Indizierungszeiten pro Kunde** – Cron-Zeiten aus der Site-Config statt aus der gemeinsamen YAML des Commons-Projekts ([Ticket 16](tickets/16-schedule-per-customer.md)).
- **Inkrementelles Crawlen** – Conditional Requests (`ETag`/`If-Modified-Since`) und robots.txt-`Crawl-delay`; `RequestExecutor` und ein Per-URL-State müssten das aufnehmen.
- **Streaming bis Solr** – nur sinnvoll mit blockweisem `update()` im Search-Bundle.
- **`Indexer implements \Atoolo\Search\Indexer`** – die Interface-Methoden sind No-ops, `getSource()` liefert `''`; prüfen, ob das Interface überhaupt gebraucht wird.
