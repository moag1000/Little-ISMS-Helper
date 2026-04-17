# ISO/IEC 27002:2022 — Umsetzungshinweise (Guidance)

## Ueberblick

ISO 27002:2022 gibt Umsetzungshinweise zu den 93 Controls aus ISO 27001:2022 Annex A. Strukturiert in 4 Themenbereiche mit Attributen fuer flexible Kategorisierung.

## Kontrollstruktur

Jeder Control enthaelt:
- **Zweck**: Warum der Control existiert
- **Kontrolle**: Was umgesetzt werden muss
- **Umsetzungshinweise**: Wie es umgesetzt werden kann
- **Weitere Informationen**: Ergaenzende Hinweise

## Attribute (Neu in 2022)

| Attribut | Werte |
|----------|-------|
| **Control type** | Preventive, Detective, Corrective |
| **IS properties** | Confidentiality, Integrity, Availability |
| **Cybersecurity concepts** | Identify, Protect, Detect, Respond, Recover |
| **Operational capabilities** | Governance, Asset_mgmt, Info_protection, HR_security, Physical_security, System_security, Network_security, Application_security, Secure_configuration, Identity_access_mgmt, Threat_vuln_mgmt, Continuity, Supplier_security, Legal_compliance, IS_event_mgmt, IS_assurance |
| **Security domains** | Governance_Ecosystem, Protection, Defence, Resilience |

## Controls nach Themenbereich

### Organisatorische Controls (A.5) — 37 Controls

| Control | Titel | Typ | Hinweise |
|---------|-------|-----|----------|
| 5.1 | Policies for IS | Prev | Themenspezifische Richtlinien, Review-Zyklus |
| 5.2 | IS roles and responsibilities | Prev | RACI-Matrix, Stellenbeschreibungen |
| 5.3 | Segregation of duties | Prev | Funktionstrennungsmatrix |
| 5.4 | Management responsibilities | Prev | Vorbildfunktion, Ressourcen |
| 5.5 | Contact with authorities | Prev+Det | BSI, BaFin, Datenschutzbehoerden |
| 5.6 | Contact with special interest groups | Prev | CERT, Branchengruppen |
| 5.7 | Threat intelligence | Prev+Det | TI-Feeds, MITRE ATT&CK |
| 5.8 | IS in project management | Prev | Security Gates, Risk Assessment |
| 5.9 | Inventory of information | Prev | Asset-Register, Klassifizierung |
| 5.10 | Acceptable use of information | Prev | Nutzungsrichtlinien |
| 5.11 | Return of assets | Prev | Offboarding-Checkliste |
| 5.12 | Classification of information | Prev | Klassifizierungsschema (4 Stufen empfohlen) |
| 5.13 | Labelling of information | Prev | Kennzeichnungsverfahren |
| 5.14 | Information transfer | Prev | Verschluesselung, sichere Kanaele |
| 5.15 | Access control | Prev | Need-to-know, Least-privilege |
| 5.16 | Identity management | Prev | Lebenszyklus, einzigartige IDs |
| 5.17 | Authentication information | Prev | MFA, Passwoerter, Zertifikate |
| 5.18 | Access rights | Prev | Provisionierung, Review, Deaktivierung |
| 5.19-5.23 | Supplier/Cloud security | Prev | Due Diligence, Vertraege, Monitoring |
| 5.24-5.28 | Incident management | Det+Corr | Meldewege, Klassifizierung, Forensik |
| 5.29-5.30 | Continuity | Prev+Corr | BIA, BC-Plaene, ICT-Readiness |
| 5.31-5.36 | Compliance | Prev+Det | Gesetze, IP, Records, Privacy, Audit |
| 5.37 | Documented procedures | Prev | Betriebsanweisungen |

### Personenbezogene Controls (A.6) — 8 Controls

| Control | Titel | Hinweise |
|---------|-------|----------|
| 6.1 | Screening | Vor Einstellung, regelmaessig |
| 6.2 | Terms and conditions | IS-Verpflichtung im Vertrag |
| 6.3 | IS awareness, education, training | Rollenbasiert, regelmaessig |
| 6.4 | Disciplinary process | Gestaffelt, dokumentiert |
| 6.5 | After termination/change | Fortgeltende Pflichten |
| 6.6 | Confidentiality/NDA | Vor Zugang, regelmaessig Review |
| 6.7 | Remote working | Technik + Organisation + Physisch |
| 6.8 | IS event reporting | Niedrigschwellig, ohne Sanktionsgefahr |

### Physische Controls (A.7) — 14 Controls

| Control | Titel | Hinweise |
|---------|-------|----------|
| 7.1-7.6 | Perimeter, Zutritt, Sicherheitszonen | Zonenkonzept, Besuchermanagement |
| 7.7 | Clear desk/screen | Automatische Sperre, Clean-Desk-Policy |
| 7.8-7.9 | Equipment, Off-premises | Verschluesselung, GPS-Tracking |
| 7.10 | Storage media | Klassifizierung, Verschluesselung, Vernichtung |
| 7.11-7.14 | Utilities, Cabling, Maintenance, Disposal | USV, Redundanz, Wartungsvertraege |

### Technologische Controls (A.8) — 34 Controls

| Control | Titel | Hinweise |
|---------|-------|----------|
| 8.1 | User endpoint devices | MDM, Verschluesselung, Patching |
| 8.2-8.5 | Privileged access, Restrictions, Authentication | PAM, RBAC, MFA |
| 8.6 | Capacity management | Monitoring, Schwellwerte, Skalierung |
| 8.7 | Protection against malware | EDR, Sandbox, Updates |
| 8.8 | Management of technical vulnerabilities | Scanning, CVSS-Bewertung, Patching |
| 8.9 | Configuration management | Baseline, Haertung, Drift-Detection |
| 8.10-8.12 | Deletion, Masking, DLP | Loeschkonzept, Pseudonymisierung |
| 8.13-8.14 | Backup, Redundancy | 3-2-1-Regel, Geo-Redundanz |
| 8.15-8.17 | Logging, Monitoring, Clock sync | SIEM, Korrelation, NTP |
| 8.18 | Privileged utility programs | Einschraenkung, Protokollierung |
| 8.19 | Installation of software | Whitelisting, Signaturpruefung |
| 8.20-8.23 | Networks security | Segmentierung, Filtering, Zero Trust |
| 8.24 | Use of cryptography | Algorithmen, Schluessellaengen, Key Mgmt |
| 8.25-8.28 | Secure development | SDLC, Code Review, SAST/DAST |
| 8.29 | Security testing | Pentest, Vulnerability Assessment |
| 8.30-8.31 | Outsourced dev, Separation | Vertraege, Dev/Test/Prod |
| 8.32-8.34 | Change mgmt, Test info, Audit testing | CAB, Anonymisierung, Schutz |