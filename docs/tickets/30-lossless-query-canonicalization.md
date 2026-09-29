# 30 – Query-String verlustfrei kanonisieren

**Typ:** Bug · **Später** (2.x) · Release-Review 2.0

## Hintergrund
`UrlCanonicalizer` baut den Query-String per `parse_str` / `http_build_query` neu (`src/Pipeline/Collector/UrlCanonicalizer.php:72-75`) – auch wenn `sp_strip_query_params_active` aus ist. Der Server bekommt dann eine andere URL:

| Link | Abgefragt |
|---|---|
| `?tag=a&tag=b` | `?tag=b` |
| `?page.no=2` | `?page_no=2` |
| `?print` | `?print=` |
| `?a[]=1` | `?a%5B0%5D=1` |

→ falsche Seite oder 404; Seiten hinter wiederholten Keys (Filter, Paginierung) gehen verloren.

## Umsetzung
- Roh an `&` splitten, Paare verwerfen, deren dekodierter Name in der Strip-Liste steht, den Rest byte-genau behalten (Reihenfolge unverändert).
- Ohne aktive Strip-Liste Query gar nicht anfassen.

## Akzeptanzkriterien
- Alle Zeilen der Tabelle bleiben unverändert; Strip entfernt nur die konfigurierten Parameter.

## Dateien
`src/Pipeline/Collector/UrlCanonicalizer.php`, `tests/UrlCanonicalizerTest.php`
