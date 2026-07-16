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

The crawler follows a **Pipe and Filter architectural pattern** orchestrated by `CrawlerManager`. The pipeline has five sequential steps:

### Execution Pipeline (CrawlerManager)

```
URLCollector → Fetcher ↓ → Parser → Processor → Indexer
                ↓ (chunked by concurrency)
           [storage handling]
```

**Data Flow:**
1. **URLCollector** - Discovers URLs from start pages using CSS selectors, respects robots.txt, applies allow/deny filters, normalizes URLs
2. **Fetcher** - Fetches HTML content in parallel (configurable concurrency), implements exponential backoff retries for failed requests
3. **Parser** - Extracts teaser data (title, intro text, datetime) from HTML using CSS selectors and OpenGraph tags, applies content scoring/filtering
4. **Processor** - Sanitizes and cleans extracted text data
5. **Indexer** - Enriches teasers and commits them to Apache Solr index

The Fetcher and Parser are chunked based on `sp_parallel_requests` configuration to manage memory and resource usage.

### Directory Structure

```
src/
├── Application/
│   ├── CrawlSiteRunner          - Orchestrates single site crawling with config context
│   ├── Schedule                 - Provides cron schedule via ScheduleProviderInterface
│   ├── StartCrawlerMessage      - Messenger message for async crawling
│   └── StartCrawlerMessageHandler - Handles async crawler invocation
├── Command/
│   └── Index                    - CLI command: bin/console crawler:scheduler-atoolo-crawler-teaser-indexer
├── Config/
│   ├── CrawlerConfig            - Accessor for configuration values (sp_* prefixed)
│   ├── CrawlerConfigContext     - Thread-safe context storing current site config
│   └── CrawlerConfigHelper      - Helper methods for type-safe config reading
├── Controller/
│   └── CrawlerManager           - Central orchestrator of the 5-step pipeline
├── Domain/Crawler/
│   ├── Steps/
│   │   ├── URLCollector         - Step 1: discover URLs
│   │   ├── Fetcher              - Step 2: fetch HTML (with retries)
│   │   ├── Parser               - Step 3: extract teaser data
│   │   ├── Processor            - Step 4: sanitize text
│   │   └── Indexer              - Step 5: commit to Solr
│   ├── Services/
│   │   ├── FieldExtractConfig   - Config for title/intro extraction
│   │   ├── DateTimeExtractConfig - Config for datetime extraction
│   │   ├── ContentScoringConfig - Config for content scoring/filtering
│   │   ├── TeaserRelevanceEvaluator - Implements content scoring logic
│   │   ├── RobotsTxtChecker     - Validates URLs against robots.txt
│   │   └── URLNormalizer        - Normalizes URLs (query param stripping, deduplication)
│   └── Ports/
│       └── RequestExecutor      - HTTP request execution with retries
```

> **Note:** `src/Proposal/` is a non-wired code skeleton for the planned next major release (see `docs/review-next_major.md`). It is not registered as services and not part of the runtime — ignore it when working on the current codebase.

### Configuration System

All configuration is **PHP array-based**, loaded via `IndexerConfigurationLoader` (from atoolo/search-bundle):

**Master Configuration** (`config/packages/atoolo_crawler_master.yaml`):
- `atoolo.crawler.schedule` - Cron expressions for execution
- `atoolo.crawler.retry_status_codes` - HTTP status codes triggering retries

**Site Configuration** (file at `base_dir/indexer/atooloTeaserCrawler.php`):
- Returns array with `data.sp_crawling_sites[]` - array of site configurations
- Each site config has 40+ `sp_*` prefixed parameters controlling:
  - Core metadata (ID, user agent, retry policy)
  - URL discovery (start URLs, CSS selectors, allow/deny lists)
  - Content extraction (title, intro text, datetime selectors)
  - Content scoring (positive/negative keyword signals)

**CrawlerConfig** class provides type-safe accessor methods for all configuration parameters.

### Key Services & Interfaces

**RequestExecutorInterface** - Ports abstraction for HTTP requests
- Implements exponential backoff (1s, 2s, 4s, 8s...) for configurable status codes
- Used by Fetcher for robust HTML retrieval

**RobotsTxtCheckerInterface** - Validates URLs against robots.txt
- Loaded from config, optional (controlled by `sp_respect_robots_txt`)

**TeaserRelevanceEvaluatorInterface** - Content scoring logic
- Evaluates teasers based on positive/negative signals in URL and body text
- Filters out teasers below `sp_content_scoring_min_score` threshold

### Dependency Injection

The bundle uses Symfony's service autowiring (`config/services.yaml`):
- All classes in `src/` are auto-registered as services
- Specific service configurations for commands and message handlers
- External dependencies injected from `atoolo/search-bundle` (Solr indexing, logger)

## Testing

**PHPUnit Configuration:** `phpunit.xml`
- Bootstrap: `vendor/autoload.php`
- Test directory: `tests/`
- Coverage reporting: HTML and Clover XML formats
- Execution order: randomized
- Memory limit: 512M

**Test Coverage:**
- Tests located in `tests/` directory (18 test files)
- Focus areas: URL collection, fetching, parsing, processing, end-to-end crawling
- E2E test (`CrawlerManagerE2ETest.php`) validates complete pipeline

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

**Type Safety:** CrawlerConfig provides accessor methods with type hints:
- `string()` - required string config
- `nullableString()` - optional string
- `bool()` - boolean with default
- `intStringList()` - handles both int and string list types

**Iterator-based Processing:** Parser and Processor use generators/iterators for memory efficiency with large result sets

**Thread-safe Configuration:** CrawlerConfigContext stores current site config (implements ResetInterface) to avoid state leakage across sites

**Symfony Messenger Integration:** StartCrawlerMessage and StartCrawlerMessageHandler enable async/scheduled crawling via Symfony Messenger

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
   Atoolo\Crawler\AtooloCrawlerTeaserIndexerBundle::class => ['all' => true],
   ```

2. Create master config at `config/packages/atoolo_crawler_master.yaml`

3. Create site config at `base_dir/indexer/atooloTeaserCrawler.php`

4. Run: `./bin/console crawler:scheduler-atoolo-crawler-teaser-indexer`

The bundle loads services from `config/services.yaml` and auto-wires all classes in `src/`.
