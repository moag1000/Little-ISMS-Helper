# Compliance Frameworks - Ladenanleitung

> Visuelle Sicht aus Compliance-Manager-Perspektive: [Sichtwechsel — Compliance-Manager](sichtwechsel/compliance-manager.md)

## 📋 Übersicht

Little ISMS Helper unterstützt **20+ internationale Compliance-Frameworks**, die Sie je nach Bedarf laden können.

![Compliance-Übersicht mit aktiven Frameworks](sichtwechsel/img/compliance-manager/compliance-overview.png)

## 🚀 Wie lade ich Compliance Frameworks?

### **Methode 1: Admin UI (Empfohlen)**

Die einfachste Methode ist über die Admin-Oberfläche:

1. **Navigieren Sie zu:** `/admin/compliance` oder klicken Sie im Admin-Menü auf **"Compliance Management"**

2. **Verfügbare Frameworks anzeigen:**
   - Sie sehen eine Liste aller verfügbaren Frameworks
   - Jedes Framework zeigt:
     - ✅ **Geladen** (grün) - Framework ist bereits aktiv
     - ⬜ **Nicht geladen** (grau) - Framework kann geladen werden

3. **Framework laden:**
   - Klicken Sie auf **"Load"** neben dem gewünschten Framework
   - Das System lädt automatisch alle Requirements
   - Sie erhalten eine Bestätigungsmeldung

4. **Framework löschen:**
   - Klicken Sie auf **"Delete"** (nur wenn Sie es wirklich entfernen möchten)
   - ⚠️ **Warnung:** Alle Requirements und Mappings werden gelöscht!

### **Methode 2: CLI Commands**

Für Entwickler oder automatisierte Prozesse:

```bash
# ISO 27001:2022
php bin/console app:load-iso27001-requirements

# BSI IT-Grundschutz
php bin/console app:load-bsi-grundschutz-requirements

# GDPR/DSGVO
php bin/console app:load-gdpr-requirements

# EU-DORA
php bin/console app:load-dora-requirements

# NIS2
php bin/console app:load-nis2-requirements

# TISAX
php bin/console app:load-tisax-requirements

# BSI C5:2020
php bin/console app:load-c5-requirements

# BSI C5:2025
php bin/console app:load-c5-2025-requirements

# ISO 27701:2019 (PIMS)
php bin/console app:load-iso27701-requirements

# ISO 27701:2025 (neu)
php bin/console app:load-iso27701v2025-requirements

# KRITIS
php bin/console app:load-kritis-requirements

# KRITIS Health
php bin/console app:load-kritis-health-requirements

# DiGAV
php bin/console app:load-digav-requirements

# TKG 2024
php bin/console app:load-tkg-requirements

# GxP
php bin/console app:load-gxp-requirements

# ISO 22301 (BCM)
php bin/console app:load-iso22301-requirements

# NIST CSF 2.0
php bin/console app:load-nist-csf-requirements

# SOC 2
php bin/console app:load-soc2-requirements

# CIS Controls v8
php bin/console app:load-cis-controls-requirements

# BSI Requirements
php bin/console app:load-bsi-requirements
```

## 📚 Verfügbare Frameworks

| Framework | Code | Industry | Mandatory | Requirements |
|-----------|------|----------|-----------|--------------|
| **ISO 27001:2022** | ISO27001 | All Sectors | No | 93 Controls (Annex A) |
| **BSI IT-Grundschutz** | BSI_GRUNDSCHUTZ | All Sectors | No | 100+ Bausteine |
| **GDPR** | GDPR | All Sectors | Yes (EU) | 99+ Articles |
| **EU-DORA** | DORA | Financial Services | Yes (EU) | 45+ Requirements |
| **NIS2** | NIS2 | All Sectors | Yes (EU) | 21 Requirements |
| **TISAX** | TISAX | Automotive | No | 150+ Requirements |
| **BSI C5:2020** | BSI-C5 | Cloud Services | No | 121 Criteria |
| **BSI C5:2025** | BSI-C5-2025 | Cloud Services | No (2027+) | 164 Criteria |
| **ISO 27701:2019** | ISO27701 | All Sectors | No | 80+ PIMS Controls |
| **ISO 27701:2025** | ISO27701_2025 | All Sectors | No | 90+ AI/Privacy |
| **KRITIS** | KRITIS | Critical Infrastructure | Yes (Germany) | 135 Controls |
| **KRITIS Health** | KRITIS-HEALTH | Healthcare | Yes (Germany) | 37 Requirements |
| **DiGAV** | DIGAV | Healthcare (DiGA) | Yes (Germany) | 38 Requirements |
| **TKG 2024** | TKG-2024 | Telecommunications | Yes (Germany) | 43 Requirements |
| **GxP** | GXP | Pharmaceutical | Yes | 65+ Requirements |
| **ISO 22301** | ISO22301 | All Sectors | No | 30+ BCM Requirements |
| **NIST CSF 2.0** | NIST-CSF | All Sectors | No (US) | 106 Controls |
| **SOC 2** | SOC2 | All Sectors | No | 64 TSC |
| **CIS Controls v8** | CIS-CONTROLS | All Sectors | No | 153 Controls |

## 🔄 Cross-Framework Mapping

![Cross-Framework-Matrix](sichtwechsel/img/compliance-manager/compliance-cross-framework.png)

Nach dem Laden mehrerer Frameworks:

1. **Automatische Mappings:**
   - Viele Frameworks haben bereits **vordefinierte Mappings** zu ISO 27001
   - Diese werden automatisch geladen

2. **Manuelle Mappings erstellen:**
   - Gehen Sie zu: `/compliance`
   - Klicken Sie auf **"Cross-Framework Mapping"**
   - Wählen Sie **Source** und **Target** Framework
   - Klicken Sie auf **"Create Mappings"**

3. **Quality Analysis:**
   - Automatische Ähnlichkeitsanalyse
   - Textual Similarity Score
   - Keyword Overlap
   - Structural Similarity
   - Quality Score (0-100)

## 📊 Gap Analysis

Nach dem Laden von Frameworks:

1. **Navigieren Sie zu:** `/compliance/framework/{id}/gaps`
2. **Sehen Sie:**
   - Alle nicht erfüllten Requirements
   - Kritische Gaps
   - Prioritäten
   - Empfehlungen

## 💾 Datenbank-Größe

**Hinweis:** Jedes Framework lädt Requirements in die Datenbank:

- **Klein:** 20-50 Requirements (~50 KB)
- **Mittel:** 50-100 Requirements (~150 KB)
- **Groß:** 100-200+ Requirements (~500 KB)

**Empfehlung:** Laden Sie nur die Frameworks, die Sie wirklich benötigen.

## ⚙️ Erweiterte Konfiguration

### Framework-spezifische Features:

**ISO 27001:**
- Annex A Controls als ComplianceRequirements
- Separate Control Entities für SOA
- Automatische Mappings

**BSI IT-Grundschutz:**
- Bausteine + BCM Module
- Schichtenmodell
- ISO 27001 Mappings

**GDPR:**
- Articles, Recitals
- Processing Activities Mapping
- Data Subject Rights

**DORA:**
- ICT Risk Management
- Third-Party Risk
- Incident Reporting

**KRITIS:**
- Sector-specific Requirements
- BSI Orientierungshilfe
- Audit Requirements

## 🔒 Sicherheit

**Berechtigungen:**
- Framework laden: `COMPLIANCE_MANAGE` oder `ROLE_ADMIN`
- Framework anzeigen: `COMPLIANCE_VIEW` oder `ROLE_USER`
- Framework löschen: `COMPLIANCE_MANAGE` oder `ROLE_ADMIN`

## 🆘 Troubleshooting

### **"Framework already loaded with X requirements"**
- Framework ist bereits geladen
- Löschen Sie das Framework zuerst, wenn Sie neu laden möchten

### **"Database error: Unique constraint violation"**
- Requirements existieren bereits
- Prüfen Sie die Datenbank: `SELECT * FROM compliance_framework WHERE code = 'CODE'`

### **"Command not found"**
- Stellen Sie sicher, dass Composer-Abhängigkeiten installiert sind: `composer install`
- Cache leeren: `php bin/console cache:clear`

### **Zu viele Requirements**
- Filtern Sie nach Applicability
- Markieren Sie nicht-relevante Requirements als "Not Applicable"

## 📖 Weiterführende Dokumentation

- **Compliance Dashboard:** `/compliance`
- **Requirements Management:** `/compliance/requirement`
- **Mappings Management:** `/compliance/mapping`
- **Gap Analysis:** `/compliance/framework/{id}/gaps`
- **Data Reuse Insights:** `/compliance/framework/{id}/data-reuse`

## 🎯 Best Practices

1. **Starten Sie mit ISO 27001** - Als Basis-Framework
2. **Laden Sie branchenspezifische Frameworks** - Nur was Sie brauchen
3. **Nutzen Sie Cross-Framework Mappings** - Reduziert Arbeit
4. **Regelmäßige Gap Analysis** - Compliance-Status überwachen
5. **Data Reuse Analysis** - Zeitersparnis berechnen

---

**Aktualisiert:** November 2025
**Version:** 1.0
**Frameworks:** 20+ verfügbar
