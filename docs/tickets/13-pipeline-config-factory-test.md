# 13 – PipelineConfigFactoryTest

**Proposal:** §11 · **Typ:** Test

## Hintergrund
`PipelineConfigFactory::create()` wirft bei fehlendem/leeren `sp_id` eine `\InvalidArgumentException` – es gibt dafür keinen Test.

## Umsetzung
- `tests/PipelineConfigFactoryTest.php`: `sp_id` fehlt, leer, kein String → Exception; gültiges Minimal-Array → `PipelineConfig` mit korrekter `id()`.
- Prüfen, ob die doppelte `sp_id`-Prüfung in `SitesRunner` noch nötig ist (die Factory-Exception wird ohnehin pro Site gefangen) – ggf. entfernen und `invalidSites` über den Exception-Typ zählen.

## Akzeptanzkriterien
- Test grün, Mutation (`composer test:infection`) tötet die Validierungs-Mutanten.

## Dateien
`tests/PipelineConfigFactoryTest.php`, ggf. `src/Application/SitesRunner.php`
