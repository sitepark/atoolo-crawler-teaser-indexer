# Atoolo-Modul: Teaser-Crawler

[![codecov](https://codecov.io/gh/sitepark/atoolo-crawler-teaser-indexer/graph/badge.svg?token=qmIoUbUs3h)](https://codecov.io/gh/sitepark/atoolo-crawler-teaser-indexer)
![phpstan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)
![php](https://img.shields.io/badge/PHP-8.2-blue)
![php](https://img.shields.io/badge/PHP-8.3-blue)
![php](https://img.shields.io/badge/PHP-8.4-blue)

## 1 Overview

The crawler automates the collection of teaser content (title, intro text, date, link) from a specific website.  
It filters this data and passes the final processed information to the Apache Solr index in order to make the teaser content searchable.  

The architecture is modular and follows the principles of the Symfony framework.  
The project uses the Pipes-and-Filters architectural pattern.  
This pattern was chosen to ensure loose coupling between the individual processing steps.

## 1.1 Core Processing Steps

1. `Schedule` → `CrawlerManager` →  
2. `CrawlerManager` → `URLCollector` →  
3. `CrawlerManager` → `Fetcher` →  
4. `CrawlerManager` → `Parser` →  
5. `CrawlerManager` → `Processor` →  
6. `CrawlerManager` → `Indexer`

- **`Schedule`**: A scheduled task that invokes the `CrawlerManager` via a Symfony command. This enables time-controlled execution of the crawler.  
- **`CrawlerManager`**: The central coordinator that calls the components `URLCollector`, `Fetcher`, `Parser`, `Processor`, and `Indexer` in the correct order.  
- **`URLCollector`**: Collects URLs to crawl by parsing the `sitemap.xml` and filtering them based on predefined patterns.  
- **`Fetcher`**: Sends HTTP requests to retrieve the HTML content of a URL.  
- **`Parser`**: Specialized in data extraction. Uses `symfony/dom-crawler` to extract teaser data via CSS selectors or OpenGraph tags from the HTML content.  
- **`Processor`**: Responsible for data transformation. Raw data is cleaned, trimmed to a maximum length of 120 characters, and transformed into the data model required for indexing.  
- **`Indexer`**: Provides the interface to Apache Solr. Receives the processed data and submits it for indexing via the `atoolo-search-bundle`.

---

## 2 Installation and Operation

### 2.1 Installation

The application was developed as a Symfony bundle and is distributed as a Composer package.

1. Install the module via Composer:  
   `composer require atoolo/crawler-teaser-indexer`

2. Run `composer update` to resolve all dependencies.

- Run tests:  
  `vendor/bin/phpunit`

- Run the application inside the project:  
  `docker compose exec -u ${UID} fpm /var/www/-->Projectname<--/www/app/bin/console crawler:index -vvv`

- Run without indexing:  
  `php bin/console crawler:index`

---

## 3 Configuration

### 3.1 Central Orchestrating Configuration

Location in your Project:  
`config/packages/atoolo_crawler_master.yaml`

An 1 example configuration lay in: `https://github.com/sitepark/atoolo-crawler-teaser-indexer/blob/main/config/packages/atoolo_crawler_master.yaml`

Purpose:

- Defines which site crawlers exist  
- Specifies when (cron) each site crawler is executed  
- References the directory containing the actual site configurations  

The data from this file is injected via configuration.  
Therefore, after changes the cache must be rebuilt:

`./bin/console cache:clear`

```yaml
parameters:
  # Directory containing the site configurations
  atoolo.crawler.sites_config_dir: '%kernel.project_dir%/config/sites/atoolo_crawler'

  # Defines execution schedule
  # Additional files must be located in: config/sites/atoolo_crawler
  
  # "siteKey": name of the crawler YAML configuration
  
  # "schedule":
  # - Cron expression (5 fields)
  # - Format:
  # ┌ Minute (0–59)
  # │ ┌ Hour (0–23)
  # │ │ ┌ Day of month (1–31)
  # │ │ │ ┌ Month (1–12)
  # │ │ │ │ ┌ Day of week (0–6, Sun=0)
  # │ │ │ │ │
  # * * * * *
  
  # Example:
  # schedule (field 1): 0      → minute
  # schedule (field 2): 3      → hour
  # schedule (field 3): */3    → every 3 days
  # schedule (field 4-5): * *  → every month, every weekday

  atoolo.crawler.sites:
    - siteKey: "Page1"
      schedule: '0 3 * * *'
    - siteKey: "Page2"
      schedule: '15 3 * * *'
```

## Site-Specific Configurations

These configurations must be located in your Project. The directory defined by parameter `atoolo.crawler.sites_config_dir` in your `atoolo_crawler_master.yaml`.
Warnings will be thrown in the test environment and at runtime if configurations are missing.

An 2 example configuration lay in: `https://github.com/sitepark/atoolo-crawler-teaser-indexer/tree/main/config/sites/atoolo_crawler`

### Core / Meta

```yaml
parameters:
  # Unique ID for this website configuration (used for Solr)
  atoolo.crawler.id: "source_pagename"

  # Respect robots.txt
  atoolo.crawler.respect_robots_txt: false

  # Correct robots.txt URL of the target domain
  atoolo.crawler.robots_url: "https://www.example/robots.txt"

```

### URLCollector (Discovery)

```yaml
# Start URLs for the crawler
# extraction_depth: crawl depth (homepage → teaser → detail page)
atoolo.crawler.start_urls:
  - url: "https://www.example/microsite/index.php"
    extraction_depth: 2
  - url: "https://www.example/"
    extraction_depth: 2

# Section within which URLs are extracted
atoolo.crawler.link_section: ".SP-Body"

# Selector for links (recommended: #content a[href])
atoolo.crawler.link_selector: "#content a[href]"

# Allowed URL prefixes
atoolo.crawler.allow_prefixes:
  - "https://www.example/microsite/"

# Explicit exclusions of URL prefixes
atoolo.crawler.deny_prefixes:
  - "https://www.example/microsite/meta/"
  - "https://www.example/index.php?sp%3Aout=sitemap"
  - "https://www.example/microsite/ueber_uns/team.php"
  - "https://www.example/microsite/ueber_uns/Dozentenvertretung.php"
  - "https://www.example/microsite/ueber_uns/E-Mail-Kontakt.php"
  - "https://www.example/microsite/ueber_uns/Business.php"
  - "https://www.example/microsite/service/SEPA-Lastschriftmandat.php"

# Explicit exclusions of URL endings
atoolo.crawler.deny_endings:
  # Images & graphics
  - .jpg
  - .jpeg
  - .png
  - .gif
  - .svg
  - .webp
  - .ico
  - .bmp
  - .tiff

  # Documents
  - .pdf
  - .doc
  - .docx
  - .xls
  - .xlsx
  - .ppt
  - .pptx
  - .odt
  - .rtf

  # Archives
  - .zip
  - .tar
  - .gz
  - .7z
  - .rar
  - .iso

  # Web assets & data
  - .js
  - .css
  - .json
  - .xml
  - .map
  - .webmanifest

  # Media
  - .mp3
  - .mp4
  - .wav
  - .avi
  - .mov
  - .mkv
  - .webm
  - .ogg

  # Fonts & miscellaneous
  - .woff
  - .woff2
  - .ttf
  - .eot
  - .exe
  - .bin

# Teasers are always included in the final result
# unless the title cannot be determined
atoolo.crawler.forced_article_urls:
  - "https://www.example"

# URL normalization
# Query parameters are temporarily removed to detect duplicate URLs
# and later reattached.
atoolo.crawler.strip_query_params_active: true

atoolo.crawler.strip_query_params:
  - page
  - p
  - offset
  - sort
  - view
  - print
  - fbclid
  - gclid

# Limit the number of detected teasers
# Teasers are extracted from the first 100 detected URLs
atoolo.crawler.max_teaser: 100
```

### Fetcher

```yaml
# Maximum number of retry attempts for unreachable URLs
# Retries use exponential backoff: 1s, 2s, 4s, 8s, etc.
# 0 means only one attempt (no retries)
atoolo.crawler.max_retry: 3

# Status codes that trigger retries
atoolo.crawler.retry_status_codes:
  - 408
  - 429
  - 500
  - 501
  - 502
  - 503
  - 504

# Delay between requests (increase if the target system blocks requests)
atoolo.crawler.delay_ms: 500

# Maximum parallel requests per host (recommended 1–3, never above 10)
atoolo.crawler.concurrency_per_host: 3

# Clearly identifiable User-Agent
atoolo.crawler.user_agent: "Atoolo/Crawler-Teaser-Indexer/1.0 (+contact@example.org)"
```

### Parser

Default values should always be provided for selectors to ensure flexibility.
The Symfony CSS-Selector package is used to extract teaser content.
The configured values are passed directly to the package.

#### Example CSS Selectors

- HTML tag: "h1"
- ID selector: "#page-title"
- Class selector: ".page-title"

```yaml
### Title ###

# Title is mandatory; otherwise the article is not indexed
atoolo.crawler.title.present: true

# Used to clearly identify the article’s source
atoolo.crawler.title.prefix: "Pagename - "

# OpenGraph tags are preferred when extracting data
atoolo.crawler.title.opengraph: ["og:title"]

# CSS selectors (skipped if empty)
atoolo.crawler.title.css: ["h1", ".page-title"]

# Maximum character length (text is truncated and "..." appended)
atoolo.crawler.title.max_chars: 120

### Intro Text ###

# Intro text is optional
atoolo.crawler.introText.present: true

# If false, the teaser can remain even if the field is missing
atoolo.crawler.introText.required_field: false

# Preferred OpenGraph tags
atoolo.crawler.introText.opengraph: ["og:description"]

# CSS selectors (skipped if empty)
atoolo.crawler.introText.css:
  - "#content p"
  - "main p"

# Maximum character length
atoolo.crawler.introText.max_chars: 200

### DateTime ###

atoolo.crawler.datetime.present: false
atoolo.crawler.datetime.required_field: false

# If only a date is present, append 00:00:00
# Example:
# "2026-01-21" → "2026-01-21 00:00:00"
# Solr requires DateTime, not Date
atoolo.crawler.datetime.only-date: true

atoolo.crawler.datetime.opengraph: []
atoolo.crawler.datetime.css: []
```

### Filter Function "Utility Score" (Content Filter)

```yaml
# Enable scoring
atoolo.crawler.content_scoring.active: false

# Minimum required score for a teaser
atoolo.crawler.content_scoring.min_score: 7

# Positive signals
atoolo.crawler.content_scoring.positive:
  - score: 6
    match_any:
      - "/vr-bis-detail/dienstleistung/"
      - "vr-bis-detail/dienstleistung"

  - score: 4
    match_any:
      - "onlinedienstleistung"
      - "online-antrag"
      - "online apply"
      - "onlineantrag"
      - "form"
      - "appointment booking"
      - "book appointment"
      - "submit application"
      - "apply digitally"

  - score: 1
    match_any:
      - "procedure"
      - "process"
      - "legal basis"
      - "forms and links"
      - "downloads"
      - "further information"

# Negative signals
atoolo.crawler.content_scoring.negative:
  - score: -12
    match_any:
      - "/vr-bis-detail/mitarbeiter/"
      - "vr-bis-detail/mitarbeiter"

  - score: -6
    match_any:
      - "test service"
      - "asset publisher"
      - "newsletter"
      - "advertising"
      - "press office"

  - score: -3
    condition:
      body_text_length_lt: 350
```
