# 02 – XPath-Injection in FieldSource::meta()

**Proposal:** §6 Sicherheits-Altlasten · **Typ:** Security

## Hintergrund
`FieldSource::meta()` baut `"//meta[@property='$property']"` per String-Interpolation. `$property` stammt aus der Config und – seit 7.1 – potentiell aus Projekt-Extractors. Ein `'` bricht den Ausdruck bzw. erlaubt Injection.

## Umsetzung
- Auf CSS umstellen: `filter('meta[property="' . addcslashes($property, '"\\') . '"]')`.
- Alternativ XPath mit sauberem Literal-Quoting (`concat()` bei gemischten Quotes). CSS ist einfacher → bevorzugt.

## Akzeptanzkriterien
- Normale OG-Properties (`og:title`, `article:published_time`) funktionieren weiter.
- Property mit `'` bzw. `"` wirft nicht und matcht nur exakt.
- Test in `tests/FieldSourceTest.php`.

## Dateien
`src/Pipeline/Parser/FieldSource.php`, `tests/FieldSourceTest.php`
