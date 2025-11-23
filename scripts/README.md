# 🔧 Little ISMS Helper - Scripts

Dieses Verzeichnis enthält alle Automatisierungsskripte für Setup, Deployment, Quality Assurance und Tools.

## 📁 Verzeichnisstruktur

```
scripts/
├── setup/              # Setup & Datenbank-Skripte
├── deployment/         # Deployment-Prüfungen
├── quality/           # Qualitätssicherung (Übersetzungen, YAML)
├── tools/             # Hilfs-Werkzeuge (Lizenzen, PRs)
└── README.md          # Diese Datei
```

---

## 🚀 Setup & Datenbank

### setup/validate-setup.sh

**Zweck:** Umfassende Validierung der Installation (18+ Checks)

**Verwendung:**
```bash
cd scripts/setup
chmod +x validate-setup.sh
./validate-setup.sh
```

**Prüft:**
- ✅ PHP-Version (8.2+ erforderlich)
- ✅ PHP-Extensions (pdo, intl, mbstring, xml, etc.)
- ✅ Composer Dependencies
- ✅ Entity-Migration Konsistenz
- ✅ AuditLog Konfiguration
- ✅ Foreign Key Constraints
- ✅ File Permissions

**Dokumentation:** [docs/setup/SETUP_VALIDATION.md](../docs/setup/SETUP_VALIDATION.md)

---

### setup/create-database.sh

**Zweck:** Sichere Datenbank-Erstellung mit interaktiver Einrichtung

**Verwendung:**
```bash
cd scripts/setup
chmod +x create-database.sh
./create-database.sh
```

**Features:**
- ✅ Interaktive Einrichtung mit Bestätigungen
- ✅ Automatische APP_SECRET Generierung
- ✅ Optionaler Admin-User
- ✅ ISO 27001 Controls (93 Controls)
- ✅ Schema-Validierung

**Dokumentation:** [docs/setup/SETUP_TOOLS.md](../docs/setup/SETUP_TOOLS.md#2-create-databasesh)

---

### setup/reset-database.sh

**Zweck:** Datenbank-Reset bei Fehlern (VORSICHT: Löscht alle Daten!)

**Verwendung:**
```bash
cd scripts/setup
chmod +x reset-database.sh
./reset-database.sh
```

**Warnung:** ⚠️ Löscht die komplette Datenbank und erstellt sie neu!

**Features:**
- ✅ Sicherheitsabfrage vor Löschung
- ✅ Vollständiger Reset
- ✅ Neuanlage mit Migrationen
- ✅ Optional: Admin-User & ISO Controls

**Dokumentation:** [docs/setup/SETUP_TOOLS.md](../docs/setup/SETUP_TOOLS.md#3-reset-databasesh)

---

### setup/test-setup.sh

**Zweck:** Test-Setup für Entwicklungsumgebung

**Verwendung:**
```bash
cd scripts/setup
chmod +x test-setup.sh
./test-setup.sh
```

**Features:**
- ✅ Schneller Test-Setup
- ✅ Beispiel-Daten
- ✅ Entwicklungs-Konfiguration

---

## 🚢 Deployment

### deployment/deployment-check.sh

**Zweck:** Produktions-Deployment-Prüfung

**Verwendung:**
```bash
cd scripts/deployment
chmod +x deployment-check.sh
./deployment-check.sh
```

**Prüft:**
- ✅ Produktions-Umgebung (APP_ENV=prod)
- ✅ APP_SECRET Sicherheit
- ✅ Database Connection
- ✅ HTTPS Konfiguration
- ✅ File Permissions
- ✅ Cache Konfiguration

**Dokumentation:** [docs/deployment/DEPLOYMENT_PLESK.md](../docs/deployment/DEPLOYMENT_PLESK.md)

---

## 🔍 Qualitätssicherung

### quality/check_translations.py

**Zweck:** Überprüfung der Übersetzungskonsistenz (DE/EN)

**Verwendung:**
```bash
cd scripts/quality
python3 check_translations.py
```

**Prüft:**
- ✅ Fehlende Übersetzungsschlüssel
- ✅ Inkonsistenzen zwischen DE und EN
- ✅ Ungenutzte Schlüssel
- ✅ Formatierungs-Probleme

**Dokumentation:** [docs/reports/TRANSLATION_CONSISTENCY_REPORT.md](../docs/reports/TRANSLATION_CONSISTENCY_REPORT.md)

---

### quality/verify_translations_v2.py

**Zweck:** Erweiterte Übersetzungsverifizierung

**Verwendung:**
```bash
cd scripts/quality
python3 verify_translations_v2.py
```

**Features:**
- ✅ Detaillierte Verifizierung
- ✅ Platzhalter-Prüfung (%placeholder%)
- ✅ HTML-Tag-Konsistenz
- ✅ Parameter-Matching

**Dokumentation:** [docs/reports/TRANSLATION_VERIFICATION_REPORT.md](../docs/reports/TRANSLATION_VERIFICATION_REPORT.md)

---

### quality/check_yaml_duplicates.py

**Zweck:** YAML-Duplikat-Prüfung in Übersetzungsdateien

**Verwendung:**
```bash
cd scripts/quality
python3 check_yaml_duplicates.py
```

**Prüft:**
- ✅ Doppelte Schlüssel in YAML-Dateien
- ✅ Namenskonflikte
- ✅ Überschreibungen

---

### quality/check_translation_issues.py

**Zweck:** Umfassender Translation Quality Checker für Twig-Templates

**Verwendung:**
```bash
cd scripts/quality
python3 check_translation_issues.py

# Report in Datei speichern
python3 check_translation_issues.py > translation_report.txt
```

**Findet:**
- ✅ **Hardcoded Text** - Text der übersetzt werden sollte (156 Issues)
- ✅ **Untranslated Attributes** - title, aria-label, placeholder ohne Übersetzung (41 Issues)
- ✅ **Missing Trans Params** - `|trans` ohne `({}, 'domain')` (4.340 Issues)
- ✅ **Missing Domain** - Übersetzungen ohne explizite Domain (78 Issues)
- ✅ **Invalid Domain** - Verwendung nicht existierender Domänen (4 Issues)

**Output-Beispiel:**
```
📄 admin/dashboard.html.twig
   Line 42: Hardcoded text: 'Dashboard'
   💡 Suggestion: Use translation: {{ 'key'|trans({}, 'domain') }}

SUMMARY BY TYPE
  HARDCODED_TEXT..........................  156 issue(s)
  MISSING_TRANS_PARAMS.................... 4340 issue(s)
  NO_DOMAIN...............................   78 issue(s)
  UNTRANSLATED_ATTRIBUTE..................   41 issue(s)
  INVALID_DOMAIN..........................    4 issue(s)
  TOTAL................................... 4619 issue(s)
```

**Features:**
- Erkennt Hardcoded English text automatisch
- Validiert gegen 49 bekannte Translation Domains
- Prüft Accessibility-Attribute (aria-label, etc.)
- Gibt konkrete Verbesserungsvorschläge
- Gruppiert Issues nach Typ und Datei

**Best Practices Guide:** Siehe Output für Beispiele und `CLAUDE.md` für Domain-Liste

---

## 🛠️ Tools

### tools/license-report.sh

**Zweck:** Lizenz-Report-Generierung für alle Dependencies

**Verwendung:**
```bash
cd scripts/tools
chmod +x license-report.sh
./license-report.sh
```

**Generiert:**
- ✅ Detaillierter Lizenz-Report (docs/reports/license-report.md)
- ✅ Compliance-Status (163 Packages)
- ✅ Lizenzübersicht (MIT, BSD, Apache, LGPL)

**Dokumentation:** [docs/setup/SETUP_TOOLS.md#4-license-reportsh](../docs/setup/SETUP_TOOLS.md#4-license-reportsh)

---

### tools/create_pr.sh

**Zweck:** GitHub Pull Request Erstellung

**Verwendung:**
```bash
cd scripts/tools
chmod +x create_pr.sh
./create_pr.sh
```

**Features:**
- ✅ Automatische PR-Erstellung via `gh` CLI
- ✅ Branch-Detection
- ✅ Commit-Message als PR-Titel

**Hinweis:** Erfordert GitHub CLI (`gh`)

---

### tools/create_pr_url.sh

**Zweck:** GitHub PR URL-Generierung

**Verwendung:**
```bash
cd scripts/tools
chmod +x create_pr_url.sh
./create_pr_url.sh
```

**Features:**
- ✅ Generiert PR-URL für aktuellen Branch
- ✅ Branch-Detection
- ✅ Direkt zum Browser kopierbar

---

## ⚠️ Deprecation Warnings

### Root-Wrapper (Rückwärtskompatibilität)

Die folgenden Skripte im Root-Verzeichnis sind **deprecated** und leiten an die neuen Pfade weiter:

- ❌ `/validate-setup.sh` → ✅ `/scripts/setup/validate-setup.sh`
- ❌ `/create-database.sh` → ✅ `/scripts/setup/create-database.sh`
- ❌ `/reset-database.sh` → ✅ `/scripts/setup/reset-database.sh`

**Empfehlung:** Verwenden Sie die neuen Pfade in `scripts/setup/`

Die Wrapper zeigen eine Deprecation-Warnung an und leiten automatisch weiter.

---

## 📋 Schnellzugriff

### Für neue Benutzer

```bash
# 1. Setup validieren
cd scripts/setup && chmod +x validate-setup.sh && ./validate-setup.sh

# 2. Datenbank erstellen
chmod +x create-database.sh && ./create-database.sh
```

### Für Entwickler

```bash
# Übersetzungen prüfen
cd scripts/quality && python3 check_translations.py

# Lizenz-Report generieren
cd scripts/tools && chmod +x license-report.sh && ./license-report.sh
```

### Für Deployment

```bash
# Produktions-Check
cd scripts/deployment && chmod +x deployment-check.sh && ./deployment-check.sh
```

---

## 🔐 Sicherheitshinweise

- **Nie** Skripte von unbekannten Quellen ausführen
- **Immer** Skripte vor Ausführung überprüfen
- **Vorsicht** bei `reset-database.sh` - löscht alle Daten!
- **Backup** vor Produktions-Deployments erstellen

---

## 📞 Weitere Informationen

- **Haupt-README:** [../README.md](../README.md)
- **Setup-Dokumentation:** [../docs/setup/](../docs/setup/)
- **Deployment-Dokumentation:** [../docs/deployment/](../docs/deployment/)
- **Issue Tracker:** [GitHub Issues](https://github.com/moag1000/Little-ISMS-Helper/issues)

---

**Stand:** 2025-11-12
**Version:** 1.0
**Autor:** Little ISMS Helper Team
