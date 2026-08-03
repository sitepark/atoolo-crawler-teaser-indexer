+# Proposal: Next Major
+
+## Wie dieses Dokument zu lesen ist
+
+Dieses Dokument ist die **verbindliche Spezifikation** der Zielarchitektur. Unter `src/Proposal/` liegt ein **Code-Skelett**, das sie in Klassen/Signaturen skizziert — bewusst unvollständig (Stubs, keine Adapter, keine Tests). Wo Skelett und Dokument abweichen, gilt das Dokument; offene Stellen im Skelett sind mit „⚠️" markiert. Warum überhaupt umgebaut wird — und was am Bestehenden gut ist — steht kompakt in [review.md](review.md).
+
+Zwei bereits getroffene Grundsatzentscheidungen:
+1. **Ziel-Namespace `Atoolo\CrawlerIndexer`** (statt `Atoolo\Crawler`).
+2. **Indexer bleibt direkt an Solr gekoppelt** — kein Infrastructure-Port (Abschnitt 6).
+
+## Ausgangslage
+
+Das Bundle crawlt Websites, extrahiert Teaser-Daten (Titel, Intro, Datum) und indiziert sie nach Solr. Heute: eine „Pipe and Filter"-Pipeline (`URLCollector → Fetcher → Parser → Processor → Indexer`), orchestriert von `Controller\CrawlerManager`, konfiguriert über ein `sp_*`-Array pro Site. Der Minor hat die abwärtskompatiblen Bugfixes erledigt; der Major bringt die strukturellen Breaking Changes.
+
+---
+
+## 1. Namensgebung#
+
+„teaser" ist im Kern überflüssig — das Bundle crawlt, prozessiert, indiziert; dass die Daten *später* als Teaser ausgegeben werden, muss es nicht wissen. Konsequenz: `RelevanceEvaluator` → `RelevanceEvaluator` (5.1) und Base-Namespace → `Atoolo\CrawlerIndexer` (Abschnitt 2).
+
+
+# Wurde von mir zum großteil so übernommen 
+
+- die runner in src/Application gelassen 
+- die exeptions in src/Exception liegen lassen
+- die Ports in src/Ports gelassen
+- Ich habe noch diverse Classen namen angepasst, weil ich fand das sie besser auf das neue Model passen oder verständlicher sind
+
+## 2. Namespaces & Verzeichnisstruktur
+
+- # Base-Namespace `Atoolo\Crawler` → **`Atoolo\CrawlerIndexer`**. Breaking Change (Host-Apps referenzieren die Bundle-Klasse) → passt in den Major (Cutover: Abschnitt 10).
+- `Domain/` weckt DDD-Erwartungen (Domain/Application/Infrastructure), die hier nicht eingelöst werden → auflösen.
+- `Controller/` ist in Symfony für HTTP-Controller reserviert; `CrawlerManager` ist keiner → umbenennen/verschieben.
+- `Console\Application` ist für ein Bundle obsolet (Commands laufen über die Host-Console) → entfernen. (Nicht mit dem `Application\`-Namespace verwechseln.)
+
+Zielstruktur (das Skelett bildet sie unter `…\Proposal\` bereits ab):
+
+```
+src/
+├── Command/IndexCommand.php
+├── Config/                     PipelineConfig, PipelineConfigFactory  Value-Objects
+│   (FieldExtractConfig, DateTimeExtractConfig, ContentScoringConfig,
+│    ScoreRuleConfig, LengthConditionConfig, HttpFetcherConfig)
+├── Dto/                        CrawledPage, IndexEntry
+├── Pipeline/
+│   ├── CrawlerPipeline.php     (ersetzt CrawlerManager)
+│   ├── {Crawler,Parser,Processor,Indexer}StepInterface.php
+│   ├── Crawler/    CrawlerStep  HttpFetcher(Interface)  RobotsTxtChecker(Interface)
+│   ├── Parser/     ParserStep  RelevanceEvaluator(Interface)
+│   ├── Processor/  ProcessorStep
+│   └── Indexer/    IndexerStep (hängt bewusst direkt an SolrIndexService)
+├── Messenger/                  StartCrawlerMessage(Handler), Schedule
+├── CrawlerRunner.php           (gemeinsame Site-Lade-Schleife, Abschnitt 8)
+├── CrawlSiteRunner.php         (eine Site: Factory → Pipeline)
+└── AtooloCrawlerTeaserIndexerBundle.php
+```
+
+Step-Interfaces liegen direkt unter `Pipeline/`, die Default-Implementierung samt der von ihr benötigten Kollaborator-Interfaces im jeweiligen Unterordner.
+
+### 2.1 Config
+
+`CrawlerConfig` → **`PipelineConfig`**, und zwar als **immutable DTO statt Service**. Jede Site hat eine eigene Config, die während eines Laufs konstant bleibt. Statt sie als geteilten Service über einen mutierbaren `CrawlerConfigContext` zu fahren (Race-Condition-Gefahr, impliziter Zustand), wird pro Lauf **eine Instanz erzeugt und als Parameter** durch die Pipeline gereicht. Damit entfallen `CrawlerConfigContext`, `CrawlerConfigHelper` und `CrawlerConfig` als 3-Klassen-Konstrukt.
+
+Erzeugt wird sie von einem `PipelineConfigFactory`-Service (`create(array $siteData): PipelineConfig`), der das `sp_*`-Array validiert. **Bewusst:** bei ungültiger Config (z.B. fehlende `sp_id`) eine `\InvalidArgumentException` werfen statt `null` — die Site-Schleife (Abschnitt 8) fängt das pro Site ab.
+⚠️ Skelett: `PipelineConfigFactory::create()` ist ein Stub (`?PipelineConfig`, `return null`). Auf obigen Vertrag umstellen.
+
+Ablauf: `CrawlerRunner` liest die Config-Datei → pro Site `PipelineConfigFactory` → `CrawlerPipeline::run($config)`. Kein `reset()` mehr, kein geteilter Zustand.
+
+**Verfeinerung (niedrige Priorität):** `PipelineConfig` ist mit ~17 Feldern ein God-DTO; jeder Step bekommt alles, braucht aber nur eine Scheibe. Wo ein Step genau eine braucht (z.B. `CrawlerStep` → `HttpFetcherConfig`), diese einzeln übergeben; sonst als Container belassen (volle Slicing-Umstellung ist wegen der Per-Lauf-Übergabe unhandlich).
+
+
+## Prozesszeit indexer sollte passen.
+Im Indexer gibt es "return $this->progressHandler->getStatus();"
+Im der CrawlerPipeline wird das dann aufgegriffen
+$indexerStatus = $this->indexer->doIndex($processedDocuments);
+$this->logger->info('Indexer statusLine: ' . $indexerStatus->getStatusLine());
+Ausgabe 14:03:08 INFO      [app] Indexer statusLine: [FINISHED] start: 22.07.2026 14:03, time: 00h 00m 00s, processed: 10/10, skipped: 0, lastUpdate: 22.07.2026 14:03, updated: 0, errors: 0
+Problem "time: 00h 00m 00s" das sollte die Zeit sein wie lange der Crawlerprozess gesammt war oder nicht? oder ist das nur die zeiut die amn zu indizieren gebraucht hat?
+
+
+## 3. Pipeline-Architektur
+
+Habe das ganze etwas anders umgesetzt aber die idee ist gleich nur eleganter meienr meinung nach.
+
+ - `CrawlerManager` → **`CrawlerPipeline`** (Name passt, macht mehr als „crawlen").
+- **`URLCollector`  `Fetcher` zu einem `CrawlerStep` mergen.** Heute lädt der URLCollector zur Link-Entdeckung fast alle Seiten (bis auf die letzte Ebene), die der Fetcher anschließend erneut lädt — doppelter Traffic ohne Cache. Der `CrawlerStep` fetcht während der BFS und reicht jede Seite direkt weiter.
+- **`executeStep`-Wrapper streichen.** Die Vereinheitlichung von Leer-/Fehler-/Logging-Behandlung über alle Steps bringt weniger als sie kostet — die Steps sind ohnehin nicht austauschbar. Jeden Step einzeln behandeln.
+- **Ein Interface pro Step** (`CrawlerStepInterface` etc.) → Steps per Decorator modifizierbar.
+
+| Interface | Methode |
+|-----------|---------|
+| `CrawlerStepInterface`   | `crawl(PipelineConfig): iterable<CrawledPage>` |
+| `ParserStepInterface`    | `parse(iterable<CrawledPage>, PipelineConfig): iterable<IndexEntry>` |
+| `ProcessorStepInterface` | `process(iterable<IndexEntry>, PipelineConfig): iterable<IndexEntry>` |
+| `IndexerStepInterface`   | `index(iterable<IndexEntry>, PipelineConfig): IndexerResult` (Zähler, s. unten) |
+
+`CrawlerPipeline::run()` verdrahtet die Lazy-Chain und konsumiert sie im `index()`-Aufruf:
+
+```php
+public function run(PipelineConfig $config): CrawlResult
+{
+    $pages     = $this->crawlerStep->crawl($config);
+    $entries   = $this->parserStep->parse($pages, $config);
+    $processed = $this->processorStep->process($entries, $config);
+    return $this->indexerStep->index($processed, $config)->toCrawlResult();
+}
+```
+## wurde als paralelle kofigurierbare request chucks angelegt
+**Streaming:** Die Steps sind echte Generatoren — es liegt immer nur eine Seite im RAM statt aller HTMLs gleichzeitig. Heute ist das HTML-Memory zwar schon via Chunking  `unset` begrenzt, aber die Generator-Laziness ist inkonsistent (der `Processor`-Generator wird sofort per `iterator_to_array()` ausgelesen, der `Fetcher` ist gar keiner); der Neubau macht die Laziness durchgängig.
+
+**Ein zentraler try/catch — kein Widerspruch zum gestrichenen Wrapper:** Weil alles lazy ist, läuft die Arbeit erst beim `index()`-Konsum; eine Exception aus irgendeinem Step propagiert dort nach oben. Entscheidend: es wird nicht mehr pro Step ein leeres Ergebnis geschluckt.
+⚠️ Skelett: `CrawlerPipeline::run()` fängt die Exception selbst ab und wirft sie **nicht** weiter → `CrawlSiteRunner` hielte die Site fälschlich für erfolgreich. Muss propagieren.
+
+**Fehler-Policy festlegen** (heute inkonsistent): Per-Item-Fehler (eine Seite) → loggen & überspringen; Infrastruktur-/Config-Fehler (Solr weg, Config ungültig) → fatal, Lauf abbrechen.
+
+**Indexer-Cleanup:** Alte Solr-Dokumente nur löschen (`deleteExcludingProcessId`), wenn neue Einträge ankamen — bei 0 Einträgen abbrechen, sonst leert ein stiller Fehler den Index. ⚠️ +Entscheidung: Das Skelett verlangt Voll-Erfolg (`successCount >= total`) — strenger als der `cleanupThreshold` aus dem Minor. „Alles-oder-nichts" heißt: ein Fehler bei 1000 Einträgen blockiert den Cleanup dauerhaft. Threshold-Logik (tolerant) oder strikt bleiben — bewusst wählen.
+
+**`CrawlResult` statt `void`:** `run()`/`index()` geben im Skelett `void` zurück → die alte `IndexerStatus` (indiziert/gelöscht/Fehler) ist weg. Für einen Cronjob eine echte Regression. Mindestens die Indexer-Zähler zurückgeben; Upstream-Zähler (gecrawlt/übersprungen) bräuchten einen `RunMetrics`-Kollektor durch die Chain. ⚠️ Skelett: gibt `void` zurück.
+
+## 4. Typisierung (DTOs)
+
+Statt Arrays: `CrawledPage` (`url`, `html`) als Crawler-Output, `IndexEntry` (`url`, `title`, `?introText`, `?datetime`  Extension-Bag) als Parser-Output, der bis zum Indexer durchläuft.
+
+| Step | Input → Output |
+|------|----------------|
+| CrawlerStep | – → `CrawledPage` |
+| ParserStep | `CrawledPage` → `IndexEntry` |
+| ProcessorStep | `IndexEntry` → `IndexEntry` |
+| IndexerStep | `IndexEntry` → – |
+

## Das ist mir auch bewusst, aber es kann auch schnell mal zu inkonsistenten teaser führen. Zudem erhöht es den integrationsaufwand, wenn es gebaucht wird. Ich habe es trotzdem hinzugefügt, weil es sicherlich für unsere Kunden relevant ist.
+
+**1:N ist erlaubt** (Generator): Eine `CrawledPage` darf mehrere `IndexEntry` erzeugen (Übersichtsseite mit vielen `<article>`). Default ist heute 1:1; für Multi-Teaser-Seiten braucht der Parser +einen „Teaser-Locator". Nicht durch eine 1:1-Annahme im Interface verschenken.
+
+### 4.1 IndexEntry-Extension
+
+Für kundenspezifische Daten bekommt `IndexEntry` eine typisierte **Extension-Bag** (`withExtension()` / `extension(Class::class)`). Da `IndexEntry` immutable ist, reichen die `with*()`-Methoden Extensions automatisch durch alle Steps.
+⚠️ Die Bag ist aktuell ein **Eingang ohne Ausgang** — es gibt keinen sauberen Weg, eine Extension in ein Solr-Feld zu bringen (und das Anhängen im Parser per Decorator ist heikel). Lösung in Abschnitt 7.
+
+## 5. `Domain\Crawler\Services` auflösen
+
+Die `…Config`-Klassen → `Config/` (im Skelett erledigt). Es bleiben:
+- `RobotsTxtChecker` (Interface) → neben `CrawlerStep`
+- `RelevanceEvaluator` (Interface, umbenannt) → neben `ParserStep`
+- `URLNormalizer` → auflösen (5.2)

+### 5.1 RelevanceEvaluator
+
+Keyword-basiertes Relevanz-Scoring (aus `title  introText  Hauptinhalts-Text`; positive/negative Regeln  Längen-Bedingung; `forcedArticleUrls` immer relevant). Wird pragmatisch vom `ParserStep` aufgerufen — okay, aber im Klassen-Kommentar dokumentieren.
+
+Er braucht **kein rohes HTML**, aber die **geparste DOM** — denn er wertet nicht den ganzen Body aus, sondern eine über eine Selektor-Prioritätsliste gewählte Hauptinhalts-Region (Navigation/Footer raushalten). Daraus zwei Entscheidungen:
+1. **DOM (`Crawler`) übergeben, nicht einen vorausgewählten String.** Welche Region relevant ist, ist eine Scoring- keine Parser-Entscheidung. Wichtig: die DOM ist ein Aufruf-Argument *innerhalb* des Parse-Steps, kein Pipeline-Payload; entscheidend ist nur, dass das HTML nicht erneut geparst wird.
+2. **Selektor-Liste konfigurierbar** machen (neues Feld in `ContentScoringConfig`, Default = bisherige Liste), statt hartkodiert.
+
+Signatur: `relevant(IndexEntry $entry, Crawler $dom, ContentScoringConfig $config): bool`. Tradeoff: koppelt das Interface an DomCrawler — vertretbar, da das ganze Bundle damit parst.
+⚠️ Skelett: Interface nimmt `string $bodyText`, `ParserStep` übergibt naiv `filter('body')->text()` (verwirft die Selektor-Auswahl). Auf `Crawler $dom`  Config-Selektoren umstellen.
+
+### 5.2 URLNormalizer
+
+Auflösen und als private Methoden in den `CrawlerStep` ziehen. Dabei Normalisierung (Kanonisieren, Query-Stripping, Dedup) von Filterung (allow/deny/endings) trennen — beides an **einer** Stelle, nicht wie heute teils doppelt. Im Skelett bereits im `CrawlerStep` zusammengezogen.
+
+## 6. Adapter / konkrete Implementierungen
+
+Das Skelett definiert nur Interfaces; die Adapter aus dem Altcode migrieren:
+
+| Interface | Adapter | Herkunft |
+|-----------|---------|----------|
+| `HttpFetcherInterface` | `HttpFetcher` | `RequestExecutor` (Throttle, Retry/Backoff, Retry-After) |
+| `RobotsTxtCheckerInterface` | `RobotsTxtChecker` | heutiger `RobotsTxtChecker` (spatie) |
+| `RelevanceEvaluatorInterface` | `RelevanceEvaluator` | `RelevanceEvaluator`, auf DOM-Übergabe umgestellt (5.1) |
+
+**Indexer/Solr — bewusst gekoppelt:** Der `IndexerStep` hängt direkt an `SolrIndexService`. Das Bundle hat genau einen Zweck (Solr), ein Port wäre Overhead. Absicht, nicht Nachlässigkeit.
+
+**Sicherheits-Altlasten (beim Ausbau mitnehmen):**
+- ⚠️ **XPath-Injection** in `ParserStep::metaContent` (`filterXPath("//meta[@property='$property']")`) → auf `filter("meta[property=\"…\"]")` mit Escaping.
+- **User-Agent CRLF-Injection** → `\r`/`\n` strippen (im `HttpFetcher`).
+- **SSRF** — kein Host-Allowlist bei ausgehenden Requests. Mindestens als Risiko dokumentieren, ggf. minimale Allowlist.

## 7. Erweiterbarkeit / offene Nähte

Die Architektur setzt auf Symfony-Decoration (ganze Steps). Für die zwei häufigsten Custom-Stellen ist das zu grob bzw. es funktioniert nicht — dort braucht es feingranulare Nähte. 7.1/7.2 sind die wichtigsten, 7.3/7.4 Ermessen.

**7.1 Feldextraktion (häufigster Wunsch).** `extractText()` ist fest „OpenGraph → CSS". Ein Parser-Decorator hilft nicht, weil er nur fertige `IndexEntry` (ohne HTML) sieht und re-parsen müsste. Besser eine Naht *im* Parser auf der bereits geparsten DOM:
```php
interface FieldExtractorInterface {
    public function supports(string $field): bool;
    public function extract(Crawler $dom, PipelineConfig $config): mixed;
}
```
Der `ParserStep` iteriert eine per DI erweiterbare (`tagged`) Extraktor-Liste. Custom-Extraktor = ein registrierter Service.

**7.2 Extension-Ausgang (die Bag braucht eine Tür).** Der `IndexerStep` baut das Solr-Doc in einer privaten Methode mit hartkodierten `setField()` — ein zusätzliches Feld geht heute nur, indem man die Indexier-Schleife neu schreibt. Lösung: die Extension bringt ihr Mapping selbst mit, der Indexer bekommt einen Builder-Seam:
```php
interface SolrFieldContributor {
    public function contribute(DocumentBuilder $doc): void;
}
```
Der `IndexerStep` ruft `contribute()` für jede Extension. Neues Feld = eine Extension-Klasse  der Extraktor aus 7.1. Behält die Solr-Kopplung (hinter dem `DocumentBuilder`), ohne dass Extensions Solr kennen.

**7.3 Crawler-Nähte (optional).** Der `CrawlerStep` (~330 Zeilen) vereint viel. Sinnvolle Schnittstellen bei Bedarf: `UrlDiscoveryStrategy` (statt fest BFS — z.B. Sitemap/Pagination) und `LinkFilterInterface`/Normalisierung. Nebenbei: das hartkodierte `https://`-only ist eine vergrabene Policy.

**7.4 Inkrementelles Crawlen (Zukunft).** Conditional Requests (`ETag`/`If-Modified-Since`) und robots.txt-`Crawl-delay`. Nicht jetzt nötig, aber `HttpFetcher`  ein Per-URL-State-Ablageort sollten es später aufnehmen können.

**7.5 Bewusst geschlossen halten:** Solr-Kopplung nicht generalisieren; `IndexEntry`-Kernfelder nicht Map-basiert machen (dafür ist die Bag da); die 4 Steps bleiben fest — keine generische ETL-Engine.

## 8. Wiring & Einstiegspunkte

- **Doppelte Site-Lade-Logik zusammenführen:** `Command\Index` und `StartCrawlerMessageHandler` laden beide Config  iterieren Sites. Gemeinsamer `CrawlerRunner`; beide werden dünne Wrapper (Exit-Code bzw. Message).
- **`CrawlSiteRunner`** baut per Factory die Config, ruft `CrawlerPipeline::run()`, fängt Fehler pro Site. Kein `configContext` mehr.
- **`services.yaml`:** Step-Interfaces auf Default-Implementierungen mappen (damit Decorators andocken). `retry_status_codes` etc. in den `HttpFetcher`.
- **Scheduler:** `Schedule` baut die `RecurringMessage`; Cron-Ausdrücke früh (beim Boot) validieren statt still abzufangen.

## 9. Textkürzung

Truncation gehört **nur** in den `ProcessorStep`, und zwar **nach** dem `clean()` (auf dem sichtbaren Text, nicht auf rohem HTML), mit der pro Feld konfigurierten `maxChars`. Ellipsis `…` statt `...`, Länge `mb_substr(…, $maxChars - 1) . '…'`.
⚠️ Skelett: kürzt im `ParserStep` (mit `'...'`), `ProcessorStep` gar nicht. Verschieben.

## 10. Migrations-/Cutover-Plan

Das Skelett liegt parallel unter `src/Proposal/`, damit der Altcode lauffähig bleibt.
1. Adapter (6)  Wiring (8) vervollständigen, bis die Pipeline eigenständig läuft.
2. Tests portieren/neu schreiben (11), grün bekommen.
3. Altcode entfernen: `Controller/`, `Domain/`, `Console/`, alte `Config/`, alte `Steps/`.
4. `Proposal/` hochziehen, Namespace → `Atoolo\CrawlerIndexer\` (inkl. Bundle-Klasse  `composer.json`-PSR-4; Test-Namespace-Tippfehler `Atoolo\Cralwer` mitkorrigieren).
5. `CLAUDE.md`  README aktualisieren.

**Breaking Change → `2.0.0`.** CHANGELOG: Namespace/Bundle-FQCN geändert (Host-Apps müssen `config/bundles.php` anpassen); im Minor bereits als „Release Notes" markierte Verhaltensänderungen bündeln.

## 11. Tests

Es gibt bereits eine substanzielle Suite (~18 Dateien). Problem ist nicht Coverage, sondern dass sie gegen die **alte** Architektur (`CrawlerManager`, `CrawlerConfig`, Array-Steps) geschrieben ist → beim Umbau größtenteils zu portieren.

Zielbild:
- **Unit pro Step** (aus vorhandenen portieren): `CrawlerStep` (BFS/Tiefe>0/Filter/robots/Canonical/maxItems/forced), `ParserStep`, `ProcessorStep` (Truncation nach Clean), `IndexerStep` (0-Einträge/Cleanup-Policy).
- **Adapter** auf neue Signaturen nachziehen (u.a. DOM-Übergabe an Evaluator); neu: `PipelineConfigFactory` (Validierungsfehler).
- **Echtes E2E:** Der heutige `CrawlerManagerE2ETest` stubbt überwiegend die Steps. Wünschenswert: Integrationstest gegen einen Mock-HTTP-Server über die ganze Pipeline.

## 12. Umsetzungsreihenfolge

1. Config: `PipelineConfig`  `PipelineConfigFactory` (2.1).
2. Adapter migrieren (6), XPath-/CRLF-Fixes.
3. Steps finalisieren: Truncation verschieben (9), Stubs schließen.
4. Wiring: `CrawlerRunner`/`CrawlSiteRunner`/`services.yaml` (8).
5. Tests (11).
6. Cutover  Namespace  CHANGELOG (10).
