# 21 – Release-Packaging

**Typ:** Build/Release · **Blocker für 2.0** (Punkt 1) · Release-Review 2.0

## Hintergrund
1. **Version fest verdrahtet:** `composer.json:3` `"version": "1.0.2"`. Composers VCS-Repository überspringt Tags, deren Version nicht zu `composer.json` passt („Skipped tag …, tag does not match version“) → ein `2.0.0`-Tag wäre für Host- und Commons-Projekt unsichtbar. Der Release-Workflow (`composer-project` `getNextReleaseVersion`) sieht nur Tags `[0-9]*.[0-9]*.[0-9]*`; `v1.0.1`/`v1.0.2` ignoriert er → nächste Version wäre `1.1.0`. Kein `extra.branch-alias`.
2. **Offene Constraints:** überall `>=` (`composer.json:10-24`, `composer validate` warnt 12×). `atoolo/search-bundle >=1.14.2` akzeptiert ein künftiges 2.x, das den an `SolrIndexService` gekoppelten Indexer bricht; `symfony/* >=6.4` akzeptiert ungetestete 7.x/8.x. Direkt genutzte Pakete fehlen (`symfony/messenger`, `symfony/scheduler`, `atoolo/resource-bundle`, `psr/log`).
3. **App-Skeleton im Bundle:** `symfony/flex`, `symfony/runtime`, `symfony/dotenv`, `symfony/monolog-bundle` in `require` werden jedem Host aufgezwungen. `src/Kernel.php` liegt im Produktions-Namespace. `config/services.yaml:3` setzt `env(DEFAULT_URI)` im Host-Container.
4. **Keine `.gitattributes`:** Dist-Archive liefern `tests/`, `docs/` (interne Reviews/Tickets), `CLAUDE.md`, `phpunit.xml.bak` und `tools/phpunit.phar` (4,9 MB) an jede Kundeninstallation.
5. **Kleinkram:** `phpunit.xml.bak` getrackt (PHPUnit-10.1-Schema); `/phpunit.xml` steht in `.gitignore`, ist aber getrackt; `phpstan.dist.neon` (Level 6) ist tot – benutzt wird `phpstan.neon.dist`; `phpstan.neon.dist:8` excludet das nicht mehr existierende `src/Proposal`; Testsuite heißt `atoolo-resource`.

## Umsetzung
- `"version"` entfernen, `"extra": {"branch-alias": {"dev-main": "2.0.x-dev"}}`. Release-Tag `2.0.0` ohne `v` über den Workflow erzeugen (`create-github-release.yml` triggert nur ohne `v`).
- Constraints: `^1.14.2` für search-bundle, `^6.4` für Symfony (`|| ^7.0` nur, wenn getestet); fehlende direkte Abhängigkeiten ergänzen.
- flex/runtime/dotenv nach `require-dev`; monolog-bundle nur als `psr/log` verlangen. `Kernel.php` nach `tests/` (bzw. `autoload-dev`), `DEFAULT_URI` entfernen, falls nicht gebraucht.
- `.gitattributes` mit `export-ignore` für `tests/ docs/ tools/ bin/ public/ config/packages/ config/example/ CLAUDE.md phpunit.xml* phpstan* infection.json .php-cs-fixer* phpcs* symfony.lock .phive/`.
- Tote Dateien löschen, `.gitignore` bereinigen, Testsuite umbenennen.
- 1.x-Pflege: bei Bedarf `support/1.x` von `v1.0.2` anlegen, bevor 2.0 auf `main` landet.

## Akzeptanzkriterien
- `composer validate --strict` ohne Warnungen.
- `git archive HEAD | tar -t` enthält nur `src/`, `config/services.yaml`, `composer.json`, `README.md`, `CHANGELOG.md`, `LICENSE`.
- Host-Container kompiliert ohne flex/runtime/dotenv aus dem Bundle.
- Test-Release (Dry-Run) ergibt `2.0.0`.

## Dateien
`composer.json`, `.gitattributes` (neu), `.gitignore`, `config/services.yaml`, `src/Kernel.php`, `phpunit.xml`, `phpunit.xml.bak`, `phpstan.dist.neon`, `phpstan.neon.dist`
