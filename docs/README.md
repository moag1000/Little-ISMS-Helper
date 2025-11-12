# 📚 Little ISMS Helper - Dokumentationsübersicht

Willkommen in der Dokumentation des Little ISMS Helper! Dieses Verzeichnis enthält alle technischen Dokumentationen, Anleitungen und Reports des Projekts.

## 📁 Verzeichnisstruktur

```
docs/
├── setup/              # Installation & Konfiguration
├── deployment/         # Produktions-Deployment
├── architecture/       # Architektur & Design
├── phases/            # Projekt-Phasen & Reports
├── compliance/        # ISO 27001 & Compliance
├── security/          # Sicherheit & Best Practices
├── migration/         # Datenbank-Migrationen
├── ui-ux/            # UI/UX Dokumentation
└── reports/          # Qualitäts- & Audit-Reports

scripts/               # Automatisierte Scripts & Tools
├── setup/             # Database setup & validation scripts
├── quality/           # Quality & translation verification scripts
└── tools/             # License reporting & utilities
```

---

## 🚀 Setup & Deployment

Dokumentation für Installation, Konfiguration und Deployment.

### setup/

| Dokument | Beschreibung |
|----------|--------------|
| [API_SETUP.md](setup/API_SETUP.md) | REST API Konfiguration, Swagger UI, Postman Collection |
| [DOCKER_SETUP.md](setup/DOCKER_SETUP.md) | Docker Compose Setup für Entwicklung & Produktion |
| [AUTHENTICATION_SETUP.md](setup/AUTHENTICATION_SETUP.md) | RBAC, Azure OAuth/SAML, Multi-Provider Auth |
| [AUDIT_LOGGING.md](setup/AUDIT_LOGGING.md) | Automatische Änderungsverfolgung (vollständige Dokumentation) |
| [AUDIT_LOGGING_QUICKSTART.md](setup/AUDIT_LOGGING_QUICKSTART.md) | 3-Schritte Quick Start für Audit-Logging |
| [SETUP_TOOLS.md](setup/SETUP_TOOLS.md) | 3 automatisierte Scripts für fehlerfreie Installation |
| [SETUP_VALIDATION.md](setup/SETUP_VALIDATION.md) | Automatische Validierung (25 Tests) |

### deployment/

| Dokument | Beschreibung |
|----------|--------------|
| [DEPLOYMENT_WIZARD.md](deployment/DEPLOYMENT_WIZARD.md) | Schritt-für-Schritt Produktionssetup mit Web-UI |
| [DEPLOYMENT_PLESK.md](deployment/DEPLOYMENT_PLESK.md) | Strato/Plesk Deployment & "Primary script unknown" Fix |

### scripts/

Automatisierte Scripts für Setup, Validierung und Quality Assurance:

**Setup & Datenbank-Verwaltung** (`scripts/setup/`)
| Script | Beschreibung |
|--------|--------------|
| `validate-setup.sh` | Validierung von Voraussetzungen (18+ Checks) |
| `create-database.sh` | Sichere Datenbank-Erstellung mit interaktiver Einrichtung |
| `reset-database.sh` | Datenbank-Reset nach Migration-Fehlern |
| `test-setup.sh` | Validierungs-Tests für Installation (25 Tests) |

**Qualität & Translation** (`scripts/quality/`)
| Script | Beschreibung |
|--------|--------------|
| `check_translations.py` | Validierung der Translations-Konsistenz (DE/EN) |
| `verify_translations_v2.py` | Erweiterte Translation-Verifikation |

**Tools & Compliance** (`scripts/tools/`)
| Script | Beschreibung |
|--------|--------------|
| `license-report.sh` | Automatische Lizenz-Compliance-Berichte |

📖 Detaillierte Dokumentation: [setup/SETUP_TOOLS.md](setup/SETUP_TOOLS.md)

> **Note:** Backward-compatible Wrapper-Scripts sind im Root-Verzeichnis verfügbar (z.B. `./validate-setup.sh`, `./reset-database.sh`, `./license-report.sh`)

---

## 🏗️ Architektur & Design

Technische Architektur-Dokumentation und Design-Entscheidungen.

### architecture/

| Dokument | Beschreibung |
|----------|--------------|
| [SOLUTION_DESCRIPTION.md](architecture/SOLUTION_DESCRIPTION.md) | Gesamtarchitektur-Übersicht, Design-Entscheidungen |
| [ENTITY_TABLE_MAPPING.md](architecture/ENTITY_TABLE_MAPPING.md) | Vollständige Zuordnung aller 39 Entities zu DB-Tabellen |
| [DATA_REUSE_ANALYSIS.md](architecture/DATA_REUSE_ANALYSIS.md) | Intelligente Datenwiederverwendung (~10,5h Zeitersparnis) |
| [DATA_REUSE_CIRCULAR_DEPENDENCY_ANALYSIS.md](architecture/DATA_REUSE_CIRCULAR_DEPENDENCY_ANALYSIS.md) | Zirkuläre Abhängigkeitsanalyse |
| [CROSS_FRAMEWORK_MAPPINGS.md](architecture/CROSS_FRAMEWORK_MAPPINGS.md) | Multi-Framework Compliance Mappings (ISO 27001, TISAX, DORA, NIS2) |

---

## 📊 Projekt-Phasen & Reports

Vollständigkeitsberichte und Feature-Dokumentation für alle Entwicklungsphasen.

### phases/

#### Phase Completeness Reports

| Phase | Status | Dokument | Beschreibung |
|-------|--------|----------|--------------|
| Phase 2 | ✅ 100% | [PHASE2_COMPLETENESS_REPORT.md](phases/PHASE2_COMPLETENESS_REPORT.md) | BCM, Multi-Framework, Data Reuse |
| Phase 3 | ✅ 100% | [PHASE3_COMPLETENESS_REPORT.md](phases/PHASE3_COMPLETENESS_REPORT.md) | User Management, Security, RBAC |
| Phase 4 | ✅ 100% | [PHASE4_COMPLETENESS_REPORT.md](phases/PHASE4_COMPLETENESS_REPORT.md) | CRUD, Workflows, Risk Matrix |
| Phase 5 | ✅ 100% | [PHASE5_COMPLETENESS_REPORT.md](phases/PHASE5_COMPLETENESS_REPORT.md) | Reports, API, Notifications |
| **Phase 6** | 🚧 ~75% | [MODULE_COMPLETENESS_AUDIT.md](phases/MODULE_COMPLETENESS_AUDIT.md) | Aktueller Status & Lückenanalyse |

#### Phase 5 Feature-Dokumentation

| Dokument | Beschreibung |
|----------|--------------|
| [PHASE5_FINAL_FEATURES.md](phases/PHASE5_FINAL_FEATURES.md) | Finale Features-Übersicht Phase 5 |
| [PHASE5_PAKET_B.md](phases/PHASE5_PAKET_B.md) | Quick View & Filtering Features |
| [PHASE5_PAKET_C.md](phases/PHASE5_PAKET_C.md) | Dark Mode & User Preferences |
| [PHASE5_PAKET_D.md](phases/PHASE5_PAKET_D.md) | Zusätzliche Premium Features |
| [PHASE5_PREMIUM_FEATURES.md](phases/PHASE5_PREMIUM_FEATURES.md) | Premium Feature Set Dokumentation |

#### Phase 6 Implementation Docs

| Dokument | Beschreibung |
|----------|--------------|
| [PHASE6_FD_6J_IMPLEMENTATION.md](phases/PHASE6_FD_6J_IMPLEMENTATION.md) | Phase 6 spezifische Implementierungen |
| [PHASE_6L_A_CODE_REVIEW_REPORT.md](phases/PHASE_6L_A_CODE_REVIEW_REPORT.md) | Phase 6 Code Review Findings |

#### Phase 5 Short Reports

| Dokument | Beschreibung |
|----------|--------------|
| [PR_PHASE5_SHORT.md](phases/PR_PHASE5_SHORT.md) | Phase 5 Zusammenfassung |
| [PR_PHASE5_COMPLETE.md](phases/PR_PHASE5_COMPLETE.md) | Phase 5 Detaillierter Report |

---

## 🔐 Compliance & Security

ISO 27001 Compliance-Dokumentation und Sicherheitsarchitektur.

### compliance/

| Dokument | Beschreibung |
|----------|--------------|
| [ISO_COMPLIANCE_IMPLEMENTATION_SUMMARY.md](compliance/ISO_COMPLIANCE_IMPLEMENTATION_SUMMARY.md) | ISO 27001 Implementierungs-Details (96% Compliance) |
| [ISO_COMPLIANCE_IMPROVEMENTS.md](compliance/ISO_COMPLIANCE_IMPROVEMENTS.md) | Compliance-Verbesserungen & Enhancements |

### security/

| Dokument | Beschreibung |
|----------|--------------|
| [SECURITY.md](security/SECURITY.md) | Sicherheitsarchitektur & Best Practices |
| [SECURITY_IMPROVEMENTS.md](security/SECURITY_IMPROVEMENTS.md) | Security Enhancements & OWASP Compliance |

---

## 🗄️ Migration & Datenbank

Datenbank-Migrations-Dokumentation und Fixes.

### migration/

| Dokument | Beschreibung |
|----------|--------------|
| [MIGRATION_FIX.md](migration/MIGRATION_FIX.md) | Dokumentation von 5 behobenen kritischen Migrations-Fehlern |
| [MIGRATION_ORDER_CHECK.md](migration/MIGRATION_ORDER_CHECK.md) | Migration Order Verification |
| [FIX_DOCTRINE_LAZY_OBJECTS.md](migration/FIX_DOCTRINE_LAZY_OBJECTS.md) | Doctrine ORM Lazy Object Fixes |

---

## 🎨 UI/UX Dokumentation

User Interface & User Experience Guidelines und Implementierungen.

### ui-ux/

| Dokument | Beschreibung |
|----------|--------------|
| [UI_UX_QUICK_START.md](ui-ux/UI_UX_QUICK_START.md) | Keyboard Shortcuts, Command Palette (⌘K/Ctrl+K) |
| [UI_UX_IMPLEMENTATION.md](ui-ux/UI_UX_IMPLEMENTATION.md) | Progressive Disclosure, Component-Dokumentation |
| [UI_UX_PHASE2.md](ui-ux/UI_UX_PHASE2.md) | Phase 2 UI/UX Implementation Details |
| [UI_UX_PHASE3.md](ui-ux/UI_UX_PHASE3.md) | Phase 3 UI/UX Improvements |
| [UI_UX_PHASE4_COMPLETE.md](ui-ux/UI_UX_PHASE4_COMPLETE.md) | Complete Phase 4 UI/UX Specification |

---

## 📋 Qualitäts- & Audit-Reports

Automatisch generierte Reports und Audit-Ergebnisse.

### reports/

| Dokument | Beschreibung |
|----------|--------------|
| [VERIFICATION_REPORT.md](reports/VERIFICATION_REPORT.md) | Code-Nachweis für alle implementierten Features |
| [TRANSLATION_CONSISTENCY_REPORT.md](reports/TRANSLATION_CONSISTENCY_REPORT.md) | Multi-Language Support Verification (DE/EN) |
| [TRANSLATION_VERIFICATION_REPORT.md](reports/TRANSLATION_VERIFICATION_REPORT.md) | Translation Verification Details |
| [license-report.md](reports/license-report.md) | Automatisch generierter Lizenz-Report (163 Packages) |
| [security-audit-owasp-2025-rc1.md](reports/security-audit-owasp-2025-rc1.md) | OWASP Security Audit Report |

---

## 🔍 Schnellzugriff nach Anwendungsfall

### Für neue Benutzer
1. Start: [../README.md](../README.md) (Haupt-README)
2. Installation: [setup/SETUP_TOOLS.md](setup/SETUP_TOOLS.md)
3. Deployment: [deployment/DEPLOYMENT_WIZARD.md](deployment/DEPLOYMENT_WIZARD.md)
4. UI/UX Basics: [ui-ux/UI_UX_QUICK_START.md](ui-ux/UI_UX_QUICK_START.md)

### Für Entwickler
1. Architektur: [architecture/SOLUTION_DESCRIPTION.md](architecture/SOLUTION_DESCRIPTION.md)
2. Datenbank: [architecture/ENTITY_TABLE_MAPPING.md](architecture/ENTITY_TABLE_MAPPING.md)
3. API: [setup/API_SETUP.md](setup/API_SETUP.md)
4. Migration Issues: [migration/MIGRATION_FIX.md](migration/MIGRATION_FIX.md)

### Für Compliance-Manager
1. ISO 27001: [compliance/ISO_COMPLIANCE_IMPLEMENTATION_SUMMARY.md](compliance/ISO_COMPLIANCE_IMPLEMENTATION_SUMMARY.md)
2. Multi-Framework: [architecture/CROSS_FRAMEWORK_MAPPINGS.md](architecture/CROSS_FRAMEWORK_MAPPINGS.md)
3. Audit-Logging: [setup/AUDIT_LOGGING.md](setup/AUDIT_LOGGING.md)
4. Security: [security/SECURITY.md](security/SECURITY.md)

### Für Projekt-Manager
1. Roadmap: [../ROADMAP.md](../ROADMAP.md)
2. Phase Reports: [phases/](phases/)
3. Module Status: [phases/MODULE_COMPLETENESS_AUDIT.md](phases/MODULE_COMPLETENESS_AUDIT.md)
4. Projekt-Stats: [../README.md#-projekt-statistiken](../README.md#-projekt-statistiken)

---

## 📞 Weitere Ressourcen

- **Haupt-README:** [../README.md](../README.md)
- **Roadmap:** [../ROADMAP.md](../ROADMAP.md)
- **Contributing:** [../CONTRIBUTING.md](../CONTRIBUTING.md)
- **Changelog:** [../CHANGELOG.md](../CHANGELOG.md)
- **License:** [../LICENSE](../LICENSE)
- **Notices:** [../NOTICE.md](../NOTICE.md)

---

## 🆕 Neu organisiert (2025-11-12)

Diese Dokumentationsstruktur wurde komplett reorganisiert, um eine bessere Übersichtlichkeit zu gewährleisten:

- ✅ **Kategorisierung:** Dokumentation nach Themen gruppiert
- ✅ **Klare Struktur:** 9 thematische Unterverzeichnisse
- ✅ **Einfache Navigation:** README.md als zentraler Einstiegspunkt
- ✅ **Aktualisierte Links:** Alle Referenzen in README.md und ROADMAP.md angepasst

**Hauptverzeichnis bleibt für:**
- README.md (Projekt-Übersicht)
- ROADMAP.md (Projekt-Roadmap)
- CONTRIBUTING.md (Contribution Guidelines)
- CHANGELOG.md (Versionshistorie)
- LICENSE & NOTICE.md (Lizenzinformationen)

**Alle technischen Dokumentationen befinden sich nun in docs/**

---

<div align="center">

**[⬆ Zurück zum Haupt-README](../README.md)**

Made with 🛡️ for better Information Security Management

</div>
