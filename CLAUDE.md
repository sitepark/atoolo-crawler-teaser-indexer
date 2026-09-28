# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Atoolo Crawler Teaser Indexer** is a Symfony bundle that provides an automated web crawler to extract teaser content (title, introductory text, dates) from external websites and index them into Apache Solr for search discovery. The crawler is highly configurable, respects robots.txt, supports content scoring/filtering, and handles parallel requests with exponential backoff retries.

**Repository:** https://github.com/sitepark/atoolo-crawler-teaser-indexer  
**Package Type:** Symfony bundle (distributed via Composer)  
**Language:** PHP  
**PHP Support:** 8.1, 8.2, 8.3, 8.4

## Essential Build and Development Commands

### Setup
```bash
# Install dependencies
composer install

# Update dependencies
composer update

# Clear cache (required after configuration changes)
./bin/console cache:clear
```

### Code Quality & Analysis
```bash
# Run all analysis (lint, phpstan, cs-fixer, compatibility)
composer analyse

# Individual analysis commands:
composer analyse:phplint              # PHP syntax validation
composer analyse:phpstan              # Static analysis (level 9)
composer analyse:phpcsfixer           # PHP-CS-Fixer check with diff
composer analyse:compatibilitycheck   # PHP 8.3/8.4 compatibility check
```

### Code Formatting
```bash
# Fix code style issues with php-cs-fixer
composer cs-fix
composer cs-fix:php-cs-fixer
```

### Testing
```bash
# Run all PHPUnit tests with coverage
composer test
composer test:phpunit

# Run mutation testing (infection) for covered code
composer test:infection
```

### Running the Crawler
```bash
# Development: Run the crawler for all configured sites
./bin/console crawler:scheduler-atoolo-crawler-teaser-indexer -vvv

# Docker example (as shown in README):
docker compose exec -u ${UID} fpm /var/www/fillTheBlank/www bin/console crawler:scheduler-atoolo-crawler-teaser-indexer -vvv
```

### Reporting
```bash
# Generate PHPStan report (Checkstyle XML format)
composer report:phpstan
```

## Architecture Overview

The crawler follows a **Pipe and Filter architectural pattern** orchestrated by `Pipeline\CrawlerPipeline`. Four steps form one **lazy generator chain**; the work happens while the Indexer consumes it:

```
URLCollector ──▶ Parser ──▶ Processor ──▶ Indexer
(BFS + fetch)    (extract)   (clean)       (Solr)
  │
  ├─ UrlCanonicalizer  (one form per URL)
  ├─ LinkFilter        (allow/deny, endings, robots.txt)
  └─ Fetcher ─ RequestExecutor (parallel chunks, retry/backoff, throttle)
```

**Data Flow:**
1. **URLCollector** - Breadth-first crawl from the start URLs. Fetches every page exactly once (in chunks of `sp_parallel_requests`), streams the fetched pages downstream and discovers links from them: `LinkFilter::filter(UrlCanonicalizer::canonicalize(links))`.
2. **Parser** - Extracts title, intro text and datetime (OpenGraph first, then CSS; project `FieldExtractor`s are asked first). A page can yield several documents (`sp_split_html_document`, 1:N). Optional content scoring via `RelevanceEvaluator`.
3. **Processor** - Strips HTML/scripts, decodes entities, truncates to `maxChars` (ellipsis included).
4. **Indexer** - Buffers the chain once (dedup + total for the progress handler), writes the Solr documents, deletes old documents of the source only if the cleanup threshold is met.

**Errors:** per-page/per-document errors are logged and skipped inside the steps. Step-level failures are wrapped in `StepExecution` with the step name (an upstream `StepExecution` passes the downstream guards unchanged). Indexer errors raise `IndexingErrorsException`, so the site counts as failed.

### Directory Structure

```
src/
├── Application/
│   ├── SitesRunner            - Loads the site list and runs every site (shared by command and handler)
│   ├── SitesRunResult         - Outcome of one run over all sites (failed/invalid sites)
│   └── PipelineRunner         - One site: PipelineConfigFactory → CrawlerPipeline::run()
├── Command/
│   └── PipelineCommand        - bin/console crawler:scheduler-atoolo-crawler-teaser-indexer
├── Config/
│   ├── PipelineConfig         - Immutable per-site config (typed accessors for the sp_* keys)
│   ├── PipelineConfigFactory  - Validates the sp_* array, builds PipelineConfig
│   ├── PipelineConfigHelper   - Type-safe reading of the raw array
│   └── *ExtractConfig, ContentScoring*Config, LengthConditionConfig - value objects
├── Dto/
│   └── ExtractedData(Interface) - One extracted document (url, title, intro, date)
├── Exception/                 - StepExecution, IndexingErrorsException, ThresholdNotMetException
├── Messenger/
│   ├── Schedule               - Cron schedule (atoolo.crawler.schedule), fails on invalid expressions
│   ├── StartPipelineMessage
│   └── StartPipelineMessageHandler
├── Pipeline/
│   ├── CrawlerPipeline        - Orchestrator: run(PipelineConfig)
│   ├── Collector/             - URLCollector, UrlCanonicalizer, LinkFilter(Interface), RobotsTxtChecker(Interface)
│   ├── Fetcher/               - Fetcher(Interface)
│   ├── Parser/                - Parser(Interface), FieldExtractorInterface, FieldSource
│   ├── RelevanceEvaluator/    - RelevanceEvaluator(Interface), ScoreRuleConfig
│   ├── Processor/             - Processor(Interface)
│   └── Indexer/               - Indexer(Interface), coupled to SolrIndexService on purpose
└── Ports/
    └── RequestExecutor(Interface) - HTTP with retry/backoff, Retry-After, per-host throttle
```

### Configuration System

All configuration is **PHP array-based**, loaded via `IndexerConfigurationLoader` (from atoolo/search-bundle):

**Master Configuration** (`config/packages/atoolo_crawler_master.yaml`):
- `atoolo.crawler.schedule` - Cron expressions for execution
- `atoolo.crawler.retry_status_codes` - HTTP status codes triggering retries
- `atoolo.crawler.deny_endings` - URL endings never followed (merged with `sp_deny_endings`)

**Site Configuration** (file at `<resource channel configDir>/indexer/atooloTeaserCrawler.php`):
- Returns array with `data.sp_crawling_sites[]` - array of site configurations
- Each site config uses `sp_*` prefixed keys, one uniform schema (lists are always lists):
  - Core metadata (`sp_id`, user agent, retry policy, throttle)
  - URL discovery (start URLs, link selector, allow/deny prefixes and endings, robots.txt, query/fragment stripping)
  - Content extraction (title, intro text, datetime selectors; `sp_split_html_document` for 1:N)
  - Content scoring (positive/negative rules, `sp_relevance_content_selector` list)

`PipelineConfigFactory::create()` validates the array (missing `sp_id` → `\InvalidArgumentException`) and returns an immutable `PipelineConfig`.

### Dependency Injection & Extension Points

The steps are **shared container services**; the per-site `PipelineConfig` is passed **per call** as the last parameter (`collect($config)`, `extractData($pages, $config)`, …). Steps must not store site-specific state in properties.

- Every step is wired by its interface in `config/services.yaml`, so a project (or the commons project) can decorate it: `#[AsDecorator(ParserInterface::class)]`. Decorators of the same step stack (`decoration_priority`).
- `FieldExtractorInterface` (autoconfigured tag `atoolo.crawler.field_extractor`) replaces the extraction of a single field (title, intro, datetime) on the parsed block via `FieldSource`.
- `LinkFilterInterface` holds the rules for following links (decorate it e.g. for a host allowlist).
- Per-run state (`RequestExecutor` throttle, `RobotsTxtChecker` cache) implements `ResetInterface` and is cleared via `kernel.reset` after every Messenger message.
- External dependencies come from `atoolo/search-bundle` (Solr index service, progress handler, configuration loader).

## Testing

**PHPUnit Configuration:** `phpunit.xml`
- Bootstrap: `vendor/autoload.php`
- Test directory: `tests/`
- Coverage reporting: HTML and Clover XML formats
- Execution order: randomized
- Memory limit: 512M

**Test Coverage:**
- Unit tests per step and building block in `tests/`
- `CrawlerPipelineTest.php` - orchestration with stubbed steps (lazy chain, error naming)
- `CrawlerPipelineE2ETest.php` - all real steps against a fake site (`MockHttpClient`), only Solr stubbed
- `StepDecorationTest.php` - wires the real `services.yaml` and checks stacked decorators

**Running Tests:**
```bash
composer test:phpunit                    # Run all with coverage report
vendor/bin/phpunit -c phpunit.xml        # Run with PHPUnit directly
composer test:infection                  # Mutation testing
```

## Code Style & Standards

**PHP-CS-Fixer:** Enforces PSR-12 with custom rules
- Tools location: `./tools/php-cs-fixer`
- Run: `composer cs-fix:php-cs-fixer`

**PHPStan:** Static analysis at level 9 (strictest)
- Tools location: `./tools/phpstan`
- Run: `composer analyse:phpstan`

**PHPLint:** PHP syntax validation
- Tools location: `./tools/phplint`
- Run: `composer analyse:phplint`

**Compatibility Check:** PHPCodeSniffer against PHP 8.3-8.4
- Config: `phpcs.compatibilitycheck.xml`
- Run: `composer analyse:compatibilitycheck`

**Editor Config:** `.editorconfig`
- 4-space indentation, LF line endings, UTF-8 charset
- YAML files (compose.yaml) use 2-space indentation

## CI/CD Workflow

**GitHub Actions Workflows** (`.github/workflows/`):

1. **verify.yml** - Runs on push/PR (triggered by `composer-verify.yml@release/1.x`)
   - PHP 8.1 linting, testing, static analysis
   - Coverage report to Codecov

2. **create-release.yml** - Manual workflow dispatch
   - Triggered by `composer-release.yml@release/1.x`
   - PHP 8.4, creates release with changelog

3. **e2e-test.yml** - Runs after main branch push
   - Triggers external E2E test suite in `atoolo-e2e-test` repository

4. **create-github-release.yml** - Creates GitHub release tags

## Important Patterns & Conventions

**Configuration Prefixes:** All configuration keys use `sp_` prefix (e.g., `sp_id`, `sp_title_css`, `sp_content_scoring_active`)

**Type Safety:** `PipelineConfig` provides typed accessors; `PipelineConfigHelper` reads the raw array (`string()`, `bool()`, `int()`, `stringList()`, …) and logs invalid values instead of failing.

**Lazy Processing:** Collector, Parser and Processor are generators; only the Indexer buffers (once).

**Config per call:** `PipelineConfig` is immutable and passed as the last parameter; there is no shared mutable config state.

**Symfony Messenger Integration:** StartPipelineMessage and StartPipelineMessageHandler enable async/scheduled crawling via Symfony Messenger

## Dependencies

**Core Framework:**
- symfony/console (6.4.36+) - CLI commands
- symfony/framework-bundle (6.4.36+) - Symfony kernel
- symfony/http-client (6.4.36+) - HTTP requests
- symfony/css-selector (6.4.34+) - CSS selector parsing
- symfony/dom-crawler (6.4.34+) - HTML/DOM manipulation

**External Integrations:**
- atoolo/search-bundle (1.14+) - Solr indexing interface
- spatie/robots-txt (2.5.4+) - robots.txt parsing

**Dev Tools:**
- phpunit/phpunit (10.5.63+) - Testing framework
- infection/infection (0.29.9+) - Mutation testing
- squizlabs/php_codesniffer (3.13.5+) - Code standards
- phpdocumentor/type-resolver (2.0+) - PHP type resolution

## Bundle Integration

This is a Symfony bundle (`symfony-bundle` type). When installed in a customer project:

1. Register in `config/bundles.php`:
   ```php
   Atoolo\CrawlerIndexer\AtooloCrawlerTeaserIndexerBundle::class => ['all' => true],
   ```

2. Create master config at `config/packages/atoolo_crawler_master.yaml`

3. Create site config at `base_dir/indexer/atooloTeaserCrawler.php`

4. Run: `./bin/console crawler:scheduler-atoolo-crawler-teaser-indexer`

The bundle loads services from `config/services.yaml` and auto-wires all classes in `src/`.
