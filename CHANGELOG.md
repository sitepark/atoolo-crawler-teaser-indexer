# Changelog

## 2.0.0

Restructured bundle: typed, lazy pipeline with decoratable steps. See
`docs/proposal-next_major.md` for the architecture.

### Breaking changes

- **Namespace** `Atoolo\Crawler\` → `Atoolo\CrawlerIndexer\`. Update
  `config/bundles.php`:
  `Atoolo\CrawlerIndexer\AtooloCrawlerTeaserIndexerBundle::class => ['all' => true]`.
- **Renamed / moved classes** (only relevant if referenced in the host
  application, e.g. in Messenger routing):
  - `Application\StartCrawlerMessage` → `Messenger\StartPipelineMessage`
  - `Application\StartCrawlerMessageHandler` → `Messenger\StartPipelineMessageHandler`
  - `Application\Schedule` → `Messenger\Schedule` (schedule renamed, see below)
  - `Command\Index` → `Command\PipelineCommand` (command name unchanged)
  - `Controller\CrawlerManager` → `Pipeline\CrawlerPipeline`
  - `Domain\Crawler\…` → `Pipeline\…`, `Config\CrawlerConfig` → `Config\PipelineConfig`
- **Schedule renamed** `scheduler-atoolo-crawler-teaser-indexer` →
  `atoolo-crawler-teaser-indexer` (Symfony adds the `scheduler_` prefix
  itself). The Messenger transport the worker consumes changes accordingly:
  `messenger:consume scheduler_atoolo-crawler-teaser-indexer` instead of
  `scheduler_scheduler-atoolo-crawler-teaser-indexer`. Update the worker
  command (e.g. supervisor config), otherwise the crawler no longer runs.
- **New required parameter** `atoolo.crawler.deny_endings` in
  `atoolo_crawler_master.yaml` (URL endings that are never followed; merged
  with `sp_deny_endings`).
- **Step interfaces changed**: the per-site `PipelineConfig` is passed as the
  last parameter of every step call instead of being injected. Custom code
  implementing or calling the steps has to be adapted.
- **`sp_relevance_content_selector` is a list** (priority order), like every
  other list key.
- **Solr document id** is a hash of source + content instead of the URL, so one
  page can yield several documents. The first run after the update replaces
  all documents of a source.

### Behaviour changes

- Invalid cron expressions in `atoolo.crawler.schedule` fail at scheduler
  start instead of being logged and silently skipped.
- Indexer errors mark the site as failed; the command exits with `1`.
- Truncated titles and intros are at most `maxChars` long, the ellipsis `…`
  included (before: `maxChars + 1`).
- Discovered links are canonicalized (lowercase scheme and host, no default
  port) **before** the allow/deny/ending rules and robots.txt are applied.
  Configure prefixes in that form; differently spelled links are fetched once.
- Content scoring evaluates visible text only (no markup, scripts or styles);
  without a matching `sp_relevance_content_selector` the whole block is used.
  Scores of existing configurations can shift.
- `http://` links are followed as well (before: `https://` only).
- User-Agent values are stripped of CR/LF/NUL.
- robots.txt is re-read on every run of a long-running worker.

### Added

- 1:N extraction: `sp_split_html_document` (XPath list) splits a page into
  several documents.
- `sp_strip_fragments`: strip URL fragments for the given prefixes.
- Extension points: every step can be decorated (`#[AsDecorator(…Interface::class)]`,
  stackable for commons + customer project); `FieldExtractorInterface` replaces
  the extraction of title, intro or datetime; `LinkFilterInterface` holds the
  rules for following links.
- Every page is fetched exactly once (URL discovery and fetching merged),
  requests of a chunk run concurrently (`sp_parallel_requests`).

### Fixed

- XPath injection in OpenGraph/meta lookups.
- Header injection via `sp_user_agent`.
- Deny prefixes could be bypassed by links with a differently spelled host
  (upper case) or an explicit default port.
