# 15 – Doku-Cutover

**Proposal:** §10 · **Typ:** Doku
**Voraussetzung:** alle anderen Tickets (zuletzt abarbeiten)

## Umsetzung
- **CLAUDE.md:** Directory-Structure, Architektur und Config-Abschnitt auf den Ist-Stand (`Application/`, `Pipeline/`, `Config/PipelineConfig*`, `Messenger/`, `Ports/`), `src/Proposal/`-Hinweis, `Controller/`, `Domain/`, `CrawlerConfig`, `Index`-Command entfernen, Test-Anzahl aktualisieren.
- **README:** Erweiterungspunkte (FieldExtractor, EntryEnricher/SolrFieldContributor, Step-Decorator, LinkFilter), neue Config-Keys (`sp_split_html_document`, `sp_relevance_content_selector`), SSRF-Hinweis (aus 03).
- **docs/proposal-next_major.md:**
  - Tippfehler: „Namensgebung#", „- #", „`RelevanceEvaluator` → `RelevanceEvaluator`".
  - ⚠️-Skelett-Hinweise und Verweise auf `src/Proposal/` entfernen.
  - Bewusste Abweichungen festhalten: Runner in `Application/`, Exceptions in `Exception/`, `Ports/`, Klassennamen (`ExtractedData`, `URLCollector`, …), `FieldSource` statt `Crawler`, Threshold-Cleanup statt Alles-oder-nichts, minimale Fehlerweitergabe statt `CrawlResult`.
  - „+"-Häkchen auf tatsächlichen Stand bringen, offene Kommentare (Prozesszeit-Frage → beantwortet durch `Indexer::prepare()`) auflösen.
- **CHANGELOG:** Eintrag 2.0.0 – Namespace/Bundle-FQCN (`config/bundles.php` anpassen), Command-/Message-Klassen umbenannt, neue Config-Keys, Erweiterungspunkte, Verhaltensänderungen (Document-ID ist nicht mehr die URL, Truncation-Länge, Cron-Validierung, Indexer-Fehler → Exit-Code 1).

## Akzeptanzkriterien
- Keine Verweise mehr auf nicht existierende Klassen/Verzeichnisse in CLAUDE.md, README, Proposal.

## Dateien
`CLAUDE.md`, `README.md`, `docs/proposal-next_major.md`, `CHANGELOG.md`
