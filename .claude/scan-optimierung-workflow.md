# Scan-Optimierungs-Workflow

## 1. Analyse
- Dateitypen prüfen: `file *.png` oder `exiftool`
- Gesamtgröße ermitteln: `du -sh ordner/`
- DPI und Dimensionen checken: `sips -g dpiWidth -g dpiHeight -g pixelWidth -g pixelHeight datei.jpg`
- Anzahl Dateien: `find ordner -type f -iname "*.png" | wc -l`

## 2. Komprimierung

### PNG → JPEG (verlustarm)
```bash
sips -s format jpeg -s formatOptions 92 "input.png" --out "output.jpg"
```
- Qualität 92% ist praktisch verlustfrei für Scans
- Typische Ersparnis: 50-80%

### DPI reduzieren (z.B. 600 → 300 DPI)
```bash
# Formel: neue_breite = alte_breite * 300 / alte_dpi
PIXELW=$(sips -g pixelWidth "$file" | grep pixelWidth | awk '{print $2}')
DPI=$(sips -g dpiWidth "$file" | grep dpiWidth | awk '{print int($2)}')
NEWW=$((PIXELW * 300 / DPI))
sips --resampleWidth $NEWW -s dpiWidth 300 -s dpiHeight 300 "$file" --out "$file"
```

## 3. Bildanalyse für Umbenennung

### Previews erstellen (für API-Kompatibilität, max 1500px)
```bash
sips --resampleHeightWidthMax 1500 "original.jpg" --out "/tmp/preview.jpg"
```

### Namensschema
```
YYYY-MM-DD_Absender_Dokumenttyp.jpg
```
Beispiele:
- `2014-04-09_Finanzamt-Daun_Einheitswertbescheid_S1.jpg`
- `2014-07-03_KUES_TUeV-Bericht-Honda.jpg`
- `Personalausweis_Vorderseite.jpg` (ohne Datum wenn nicht relevant)

## 4. Sortierung - Typische Kategorien

| Ordner | Inhalte |
|--------|---------|
| Zeugnisse | Schulzeugnisse, Uni-Abschlüsse, Leistungsübersichten |
| Versicherungen | Hausrat, Haftpflicht, Riester, Lebensversicherung |
| Rentenversicherung | DRV-Formulare (V101, V410, R250) |
| Steuern | Lohnsteuerbescheinigungen, Steuerbescheide |
| Grundstueck_XYZ | Finanzamt, Gemeinde, Wasser/Abwasser |
| KFZ | TÜV, Zulassung, Versicherung |
| Ausweise | Personalausweis, Reisepass, Führerschein |

### Dateien verschieben per Wildcard
```bash
mv *_Finanzamt-Daun_* Grundstueck_Daun/
mv *_Rentenversicherung_* Rentenversicherung/
```

## 5. Duplikate entfernen
- Unterordner mit gleichen Inhalten identifizieren
- Nach Umbenennung im Hauptordner → Duplikat-Ordner löschen
- Testbilder (z.B. "Begrüßungsscan" mit Blumenwiese) entfernen

## Tools (macOS)
- `sips` - Native macOS Bildverarbeitung (kein Install nötig)
- `exiftool` - Metadaten auslesen/schreiben
- `du -sh` - Ordnergröße
- `find` - Dateien suchen
- `file` - Dateityp erkennen

## Typische Ergebnisse
- PNG → JPEG: ~13% Ersparnis
- 600 DPI → 300 DPI: ~20% Ersparnis
- Duplikate entfernen: variabel
- **Gesamt: 60-70% Ersparnis möglich**
