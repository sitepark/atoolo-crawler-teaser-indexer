# 07 – ExtractedDataInterface in eigene Datei

**Typ:** Aufräumen

## Hintergrund
`src/Dto/ExtractedData.php` enthält `ExtractedDataInterface` **und** `ExtractedData`. PSR-4 sucht das Interface unter `src/Dto/ExtractedDataInterface.php`; ein `instanceof`/`createMock(ExtractedDataInterface::class)`, bevor `ExtractedData.php` geladen ist, schlägt fehl. Funktioniert heute nur zufällig.

## Umsetzung
- `src/Dto/ExtractedDataInterface.php` anlegen, Interface verschieben.

## Akzeptanzkriterien
- Je eine Klasse/ein Interface pro Datei; `composer analyse` + Tests grün.

## Dateien
`src/Dto/ExtractedData.php`, `src/Dto/ExtractedDataInterface.php`
