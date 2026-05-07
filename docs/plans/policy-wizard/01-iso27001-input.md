# ISO 27001:2022 — Mandatory Policy Document Set

> **Source standard:** ISO/IEC 27001:2022 (clauses 4–10) + Annex A (referencing ISO/IEC 27002:2022 controls).
> **Scope of this document:** Expert input from the ISMS-Specialist for the Policy-Wizard feature. No code, no UI mock-ups — pure normative content + design recommendations.
> **Last reviewed:** 2026-05-06 against ISO/IEC 27001:2022 + Amendment 1:2024 (climate-change wording in Cl. 4.1/4.2).

---

## 1. Top-Level Information Security Policy

### 1.1 Normative anchor
ISO/IEC 27001:2022 **Clause 5.2 "Policy"** is the only clause that uses the unambiguous wording:

> *"Top management shall establish an information security policy that …"*

It is the single most-cited document in any certification audit. Without it the ISMS does not exist on paper. Clause 5.2 is supported by Clause 7.5 (Documented information) which mandates control of distribution, version, retention and review.

### 1.2 Required content blocks (Cl. 5.2 a–g)
The standard is unusually prescriptive here. The policy **shall**:

| § | Required content | Wizard variable |
|---|------------------|-----------------|
| 5.2 a | Be appropriate to the purpose of the organisation | `tenant.purpose_statement` |
| 5.2 b | Include information security objectives **or** provide the framework for setting them | link to objectives module / `infosec.objectives_framework` |
| 5.2 c | Include a commitment to satisfy applicable requirements | static (legal/regulatory wording, locale-specific) |
| 5.2 d | Include a commitment to continual improvement of the ISMS | static |
| 5.2 e | Be available as documented information | wizard writes `Document` record automatically |
| 5.2 f | Be communicated within the organisation | `infosec.communication_channels[]` |
| 5.2 g | Be available to interested parties as appropriate | `infosec.external_publication: bool` |

### 1.3 Approval chain
- **Author:** CISO / ISB (`tenant.ciso_user_id`).
- **Reviewer:** Compliance Manager / Datenschutzbeauftragter on the privacy-relevant sections.
- **Approver:** Top management — typically Geschäftsführung / CEO. Cl. 5.1 ("Leadership and commitment") makes top-management endorsement non-delegable. Wizard MUST capture `tenant.top_management_signatory_user_id`.
- **Recommended:** four-eyes approval workflow, leveraging the existing `four_eyes` translation domain + workflow infrastructure.

### 1.4 Review cadence
- **Mandatory trigger:** Cl. 9.3 Management Review (annual minimum) **and** "when significant changes occur" (Cl. 5.2 + 9.3.2). Most auditors expect at least once per calendar year.
- **Default wizard setting:** 12 months.
- **Maximum allowed:** 24 months — auditors push back on anything longer; policies older than 24 months are an audit finding.
- **Forced re-review triggers:** scope change, top-management change, major incident, regulatory change.

### 1.5 Tenant-settings inputs needed
- Organisation legal name + commercial register entry
- Scope statement (Cl. 4.3) — verbatim text
- ISMS context summary (Cl. 4.1/4.2 with climate-change consideration per Amd. 1:2024)
- CISO / ISB name + role + contact
- Top-management signatory name + role
- Communication channels (intranet, training portal, email)
- Locale (de_DE, en_GB) — drives template variant
- External publication flag (yes/no/extract-only)

---

## 2. Mandatory Topic-Specific Policies

> **Note on wording:** ISO 27001:2022 Annex A is **not** a list of mandatory documents — it is a list of controls that "may be implemented." The mandatory aspect comes from Cl. 6.1.3 (Risk Treatment): controls deemed applicable in the SoA become operational obligations, and most A.5/A.8 controls explicitly say "*The organization shall establish, implement, maintain and continually improve a topic-specific policy on …*" inside ISO/IEC 27002:2022. That wording — repeated for ~14 topics — is the legal hook.

### 2.1 Acceptable Use
- **Reference:** A.5.10 *Acceptable use of information and other associated assets*
- **Canonical name:** Acceptable Use Policy / **DE:** Richtlinie zur akzeptablen Nutzung von Informationen und Assets
- **Why mandatory:** ISO 27002:2022 §5.10 → "*Rules for the acceptable use … shall be identified, documented and implemented.*" Almost universally applicable; if marked Not-Applicable in SoA, justification is hard.
- **Required sections:** scope/audience, asset categories covered (corporate devices, BYOD, cloud accounts, removable media, personal email), permitted uses, prohibited uses, social-media/AI-tool usage clause (mandatory since 2023 per regulator guidance), monitoring notice, sanctions reference (HR policy link), reporting channel.
- **Tenant inputs:** BYOD allowed (yes/no), AI-tool stance (forbidden / approved-list / open), removable-media stance, monitoring stance.
- **Linked Annex A controls (auto-link on SoA):** A.5.10, A.5.11 (Return of assets), A.6.7 (Remote working), A.7.9 (Security of off-premises assets), A.8.1 (User endpoint devices).
- **Review cadence:** Annual + on change to working-mode (e.g. new BYOD programme).

### 2.2 Access Control
- **Reference:** A.5.15 *Access control*
- **Canonical name:** Access Control Policy / **DE:** Zugriffskontroll-Richtlinie
- **Why mandatory:** ISO 27002:2022 §5.15 → "*Rules to control physical and logical access … shall be established and implemented based on business and information security requirements.*" Plus A.5.18 ("Access rights") mandates documented review. This is **the** policy auditors test first.
- **Required sections:** access-control principles (least privilege, need-to-know, segregation of duties), authorisation procedure, periodic review frequency, joiner-mover-leaver process, privileged access rules, remote access, third-party access.
- **Tenant inputs:** access review cadence (default 6 months), JML-process owner (HR or IT or both), MFA mandate scope, privileged-access tooling (PAM yes/no).
- **Linked Annex A:** A.5.15, A.5.16, A.5.17, A.5.18, A.8.2 (Privileged access rights), A.8.3 (Information access restriction), A.8.4 (Access to source code), A.8.5 (Secure authentication).
- **Review cadence:** Annual + on RBAC-architecture change.

### 2.3 Information Classification & Handling
- **Reference:** A.5.12 *Classification of information* + A.5.13 *Labelling of information* + A.5.14 *Information transfer*
- **Canonical name:** Information Classification & Handling Policy / **DE:** Richtlinie zur Klassifizierung und Handhabung von Informationen
- **Why mandatory:** ISO 27002:2022 §5.12 → "*Information should be classified … in accordance with the information security needs of the organization …*" + §5.13 mandates "*an appropriate set of procedures for information labelling shall be developed and implemented*". This is the basis for ALL handling rules.
- **Required sections:** classification scheme (3 vs 4 levels), criteria per level (CIA), labelling rules per medium (paper, file, email subject, document footer), handling matrix (storage / transmission / destruction per level), declassification process.
- **Tenant inputs:** number of classification levels (3: public/internal/confidential vs 4: + strictly-confidential/secret), label format, retention defaults per level.
- **Linked Annex A:** A.5.12, A.5.13, A.5.14, A.5.33 (Protection of records), A.7.10 (Storage media), A.8.10 (Information deletion), A.8.12 (Data leakage prevention).
- **Review cadence:** Annual; classification scheme change triggers re-classification project (out of scope for wizard).

### 2.4 Information Transfer
- **Reference:** A.5.14 *Information transfer*
- **Canonical name:** Information Transfer Policy / **DE:** Richtlinie zur Informationsübertragung
- **Why mandatory:** ISO 27002:2022 §5.14 → "*Information transfer rules, procedures or agreements shall be in place for all types of transfer facilities …*" Three variants explicitly named: electronic, physical, verbal. Often merged with Acceptable Use, but auditors prefer it separately if email/cloud-storage volume justifies it.
- **Required sections:** electronic transfer rules (encryption requirement per classification level), physical transfer rules (couriers, registered mail), verbal transfer rules (no client data in public spaces, no speakerphone in open offices), third-party agreements requirement, NDA reference.
- **Tenant inputs:** approved file-transfer channels (e.g. Nextcloud, SFTP, MS OneDrive), encryption-at-rest requirement per level, NDA template reference.
- **Linked Annex A:** A.5.14, A.6.6 (Confidentiality / NDA), A.8.20–A.8.23 (Network controls), A.8.24 (Cryptography).
- **Review cadence:** Annual + on tooling change (e.g. introducing a new file-share tool).

### 2.5 Identity Management
- **Reference:** A.5.16 *Identity management*
- **Canonical name:** Identity Management Policy / **DE:** Identitätsmanagement-Richtlinie
- **Why mandatory:** ISO 27002:2022 §5.16 → "*The full lifecycle of identities shall be managed.*" Lifecycle wording = mandatory. Often combined with Access Control but should be separate when SSO/IdP/Federated-Identity scope is material.
- **Required sections:** identity types (employee, contractor, service account, technical, customer), identity creation/deletion/suspension, naming convention, uniqueness rules, lifecycle hooks (on-/off-boarding), identity-provider scope (e.g. Entra ID, Keycloak), federated identity rules.
- **Tenant inputs:** primary IdP, naming convention, service-account ownership rule (every account must have a human owner — NIS2/DORA hardening).
- **Linked Annex A:** A.5.16, A.5.18, A.8.5.
- **Review cadence:** Annual + on IdP change.

### 2.6 Authentication Information
- **Reference:** A.5.17 *Authentication information*
- **Canonical name:** Authentication Information Policy / **DE:** Richtlinie für Authentisierungsinformationen
- **Why mandatory:** ISO 27002:2022 §5.17 → "*Allocation and management of authentication information shall be controlled by a management process …*" Replaces the old "Password Policy" with broader scope (passwords, tokens, certificates, biometrics, secrets).
- **Required sections:** allocation process, password rules (length, complexity, rotation — note: NIST SP 800-63B says NO mandatory rotation; align), MFA mandate, secret-storage rules (no hardcoded secrets), recovery process, certificate-lifecycle rules.
- **Tenant inputs:** minimum password length, MFA scope (all-users / privileged-only), secrets-management tool (Vault / AWS Secrets Manager / Azure Key Vault), token validity periods, alignment statement (NIST 800-63B vs BSI TR-02102).
- **Linked Annex A:** A.5.17, A.8.5 (Secure authentication), A.5.16.
- **Review cadence:** Annual + on auth-mechanism change.

### 2.7 Cryptography
- **Reference:** A.8.24 *Use of cryptography*
- **Canonical name:** Cryptography Policy / **DE:** Kryptografie-Richtlinie
- **Why mandatory:** ISO 27002:2022 §8.24 → "*Rules for the effective use of cryptography, including cryptographic key management, shall be defined and implemented.*" "Shall" wording = mandatory. Plus regulatory drivers (DORA, BSI TR-02102, NIS2).
- **Required sections:** approved algorithms + key strengths (AES-256, RSA-3072+, ECDSA P-256+, SHA-256+), prohibited algorithms (DES, RC4, MD5, SHA-1, RSA <2048), TLS minimum version (1.2+, prefer 1.3), key management lifecycle (generation, distribution, storage, rotation, revocation, destruction), HSM/KMS scope, certificate authority strategy, key escrow rules (legal holds), post-quantum-readiness statement (regulator focus 2026+).
- **Tenant inputs:** crypto-baseline (BSI TR-02102 / NIST FIPS 140-3 / industry default), approved key strengths, KMS/HSM tooling, CA strategy (internal / external), PQC-roadmap statement.
- **Linked Annex A:** A.8.24, A.5.14 (Information transfer), A.8.25–A.8.26 (Secure development).
- **Review cadence:** Annual + on algorithm-deprecation event (e.g. NIST PQC standards finalisation).

### 2.8 Backup
- **Reference:** A.8.13 *Information backup*
- **Canonical name:** Backup Policy / **DE:** Backup-Richtlinie
- **Why mandatory:** ISO 27002:2022 §8.13 → "*Backup copies of information, software and systems shall be maintained and regularly tested in accordance with the agreed topic-specific policy on backup.*" Explicit "topic-specific policy" mention — this is one of the few that names the policy directly.
- **Required sections:** scope (which systems/data), 3-2-1 strategy or equivalent, frequency per data tier, retention per data tier, off-site / immutable / air-gap rules, encryption-at-rest, restore-test cadence, RPO targets per system tier (link to BCM RTOs), responsibility matrix.
- **Tenant inputs:** RPO per tier (e.g. tier-1 = 1h, tier-2 = 24h, tier-3 = 7d), backup tooling, off-site location, immutability requirement (yes/no), restore-test cadence (default quarterly for tier-1).
- **Linked Annex A:** A.8.13, A.5.30 (ICT readiness for BC), A.8.14 (Redundancy of information processing facilities).
- **Review cadence:** Annual + on infrastructure change.

### 2.9 Logging
- **Reference:** A.8.15 *Logging* (+ A.8.16 *Monitoring activities* + A.8.17 *Clock synchronization* tightly coupled)
- **Canonical name:** Logging & Monitoring Policy / **DE:** Protokollierungs- und Monitoring-Richtlinie
- **Why mandatory:** ISO 27002:2022 §8.15 → "*Logs that record activities, exceptions, faults and other relevant events shall be produced, stored, protected and analysed.*" Plus DORA Art. 16 + NIS2 Art. 21 demand logs explicitly.
- **Required sections:** log scope (what is logged), retention per log type, log protection (write-once / append-only / SIEM ingestion), correlation rules, monitoring use-cases, alert thresholds, log-review cadence, privacy-by-design (GDPR Art. 6/32 — works-council co-determination in DE!).
- **Tenant inputs:** SIEM tool (or "no SIEM"), log retention per category (default: security 12m, application 90d, system 90d), works-council agreement reference (DE-only field), monitoring use-cases approved.
- **Linked Annex A:** A.8.15, A.8.16, A.8.17, A.5.28 (Collection of evidence).
- **Review cadence:** Annual + on SIEM-tool change. Works-council re-consultation on monitoring change is a hard requirement in DE.

### 2.10 Patch / Vulnerability Management
- **Reference:** A.8.8 *Management of technical vulnerabilities*
- **Canonical name:** Vulnerability & Patch Management Policy / **DE:** Schwachstellen- und Patch-Management-Richtlinie
- **Why mandatory:** ISO 27002:2022 §8.8 → "*Information about technical vulnerabilities of information systems in use shall be obtained, the organization's exposure to such vulnerabilities evaluated and appropriate measures taken.*" "Shall" wording.
- **Required sections:** vulnerability sources (CVE feeds, vendor advisories, BSI WID, threat-intel input), scanning frequency, asset coverage (must align with asset inventory A.5.9), severity classification (CVSS 4.0 since 2024), patch SLAs per severity (e.g. critical 7 days, high 30 days), exception process, emergency-patching procedure, third-party patches.
- **Tenant inputs:** scanning tooling, patch SLAs per severity, exception-approval authority, emergency-patch window.
- **Linked Annex A:** A.8.8, A.8.9 (Configuration management), A.8.32 (Change management), A.5.7 (Threat intelligence).
- **Review cadence:** Annual + on tooling change. SLAs may need shortening if threat-landscape changes (e.g. CVE-2024-x publicly exploited within hours).

### 2.11 Malware
- **Reference:** A.8.7 *Protection against malware*
- **Canonical name:** Malware Protection Policy / **DE:** Schutz vor Schadsoftware-Richtlinie
- **Why mandatory:** ISO 27002:2022 §8.7 → "*Protection against malware shall be implemented and supported by appropriate user awareness.*" "Shall" wording.
- **Required sections:** technical controls (EDR/AV/EPP, sandboxing, email gateway), user-awareness link (training policy), update mechanism, exception process, response to detection (link to incident-response policy).
- **Tenant inputs:** EDR/AV product, scope (workstations / servers / mobile / cloud-VMs), update frequency, USB-control stance, scan-on-access vs. scheduled.
- **Linked Annex A:** A.8.7, A.6.3 (Awareness), A.8.32 (Change management).
- **Review cadence:** Annual + on tooling change.

### 2.12 Secure Configuration
- **Reference:** A.8.9 *Configuration management*
- **Canonical name:** Secure Configuration Policy / **DE:** Härtungs- und Konfigurations-Richtlinie
- **Why mandatory:** ISO 27002:2022 §8.9 → "*Configurations, including security configurations, of hardware, software, services and networks shall be established, documented, implemented, monitored and reviewed.*" "Shall" wording.
- **Required sections:** baseline source (CIS Benchmarks / BSI IT-Grundschutz / vendor hardening guides), scope per technology family, deviation/exception process, drift-detection mechanism, configuration-as-code stance, change-management linkage.
- **Tenant inputs:** baseline source per technology family, configuration-management tool (Ansible / Puppet / GPO), drift-detection tool, exception-approval authority.
- **Linked Annex A:** A.8.9, A.8.32 (Change management), A.8.8 (Vulnerabilities).
- **Review cadence:** Annual + on baseline-source update (e.g. CIS Benchmark v3 release).

### 2.13 Network Security
- **Reference:** A.8.20 *Networks security* + A.8.21 *Security of network services* + A.8.22 *Segregation of networks* + A.8.23 *Web filtering*
- **Canonical name:** Network Security Policy / **DE:** Netzwerk-Sicherheits-Richtlinie
- **Why mandatory:** ISO 27002:2022 §8.20 → "*Networks and network devices shall be secured, managed and controlled to protect information in systems and applications.*" Plus §8.22: "*Groups of information services, users and information systems shall be segregated in the organization's networks.*" "Shall" wording in both.
- **Required sections:** zoning model (DMZ / internal / management / OT), firewall-rule lifecycle (request, review, removal), VPN rules, wireless rules, web-filter scope, DNS-security stance (DoH/DoT), zero-trust roadmap (regulator interest 2026+).
- **Tenant inputs:** zoning model, VPN tooling, wireless authentication mode (WPA3-Enterprise mandate), web-filter category list, DNS provider, ZT-roadmap reference.
- **Linked Annex A:** A.8.20, A.8.21, A.8.22, A.8.23, A.8.16 (Monitoring).
- **Review cadence:** Annual + on architectural change.

### 2.14 Secure Development
- **Reference:** A.8.25 *Secure development life cycle* + A.8.26 *Application security requirements* + A.8.27 *Secure system architecture and engineering principles* + A.8.28 *Secure coding* + A.8.29 *Security testing in development and acceptance* + A.8.30 *Outsourced development*
- **Canonical name:** Secure Development Policy / **DE:** Richtlinie für sichere Entwicklung
- **Why mandatory:** ISO 27002:2022 §8.25 → "*Rules for the secure development of software and systems shall be established and applied.*" Plus six related controls all marked "shall." Skipping this with N/A is only credible if the organisation does not develop software in-house **and** has no outsourced development — vanishingly rare.
- **Required sections:** SDLC stage-gates with security checks, threat-modelling requirement, secure-coding standards (OWASP ASVS / CERT / language-specific), code-review requirement, static / dynamic / dependency scanning, third-party dependency-management, security-testing scope (SAST / DAST / IAST / SCA), pen-test cadence, outsourced-development clauses (security-requirements in contracts), sandbox/separated-environments rule.
- **Tenant inputs:** in-house development scope, SDLC framework (Scrum / SAFe / Waterfall), SAST tool, DAST tool, SCA tool, pen-test cadence, outsourced-development list (link to suppliers module).
- **Linked Annex A:** A.8.25–A.8.30, A.8.31 (Separation of dev/test/production environments), A.8.32 (Change management), A.5.20 (Addressing information security in supplier agreements).
- **Review cadence:** Annual + on SDLC-framework change.

### 2.15 Supplier Relationships
- **Reference:** A.5.19 *Information security in supplier relationships* + A.5.20 *Addressing information security in supplier agreements* + A.5.21 *Managing information security in the ICT supply chain* + A.5.22 *Monitoring, review and change management of supplier services*
- **Canonical name:** Supplier Security Policy / **DE:** Lieferanten-Sicherheits-Richtlinie
- **Why mandatory:** ISO 27002:2022 §5.19 → "*Processes and procedures shall be defined and implemented to manage the information security risks associated with the use of suppliers' products or services.*" "Shall." Plus DORA Art. 28–30 hardens this (TPP-Register, exit strategies). NIS2 Art. 21 also mandates supply-chain security.
- **Required sections:** supplier classification (criticality tiers), onboarding due-diligence (questionnaire / audit / certification check), contractual clauses required (security, audit-right, breach-notification, sub-processor approval), ongoing-monitoring cadence, exit strategy (DORA Art. 28), ICT-third-party register (DORA RTS), sub-supplier visibility (Nth-party).
- **Tenant inputs:** supplier-criticality tiers (default 3), questionnaire template, audit-right scope, register-of-information field-set (DORA — heavier than ISO alone), exit-strategy template.
- **Linked Annex A:** A.5.19, A.5.20, A.5.21, A.5.22, A.5.23 (Information security for use of cloud services).
- **Review cadence:** Annual + on supplier-portfolio change.

### 2.16 Information Security in Project Management
- **Reference:** A.5.8 *Information security in project management*
- **Canonical name:** Information Security in Project Management Policy / **DE:** Informationssicherheit im Projektmanagement
- **Why mandatory:** ISO 27002:2022 §5.8 → "*Information security shall be integrated into project management.*" "Shall" wording. Often a one-pager with stage-gate checklist; auditors accept compactness here.
- **Required sections:** scope (which projects? — usually all, threshold by budget/risk), project-initiation security questionnaire, security stage-gates per project phase, risk-assessment requirement, sign-off authority per security risk-tier.
- **Tenant inputs:** project-management framework (PRINCE2 / PMI / Scrum), gate-keeper role, risk-tier thresholds.
- **Linked Annex A:** A.5.8, A.5.9 (Asset inventory), A.6.6 (Confidentiality).
- **Review cadence:** Annual + on PM-framework change.

### 2.17 Privacy / PII Handling
- **Reference:** A.5.34 *Privacy and protection of PII*
- **Canonical name:** Privacy & PII Protection Policy / **DE:** Datenschutz- und PII-Schutz-Richtlinie
- **Why mandatory:** ISO 27002:2022 §5.34 → "*The organization shall identify and meet the requirements regarding the preservation of privacy and protection of PII according to applicable laws and regulations and contractual requirements.*" "Shall" wording. **Caveat:** If the organisation has a separate ISO 27701 PIMS or a stand-alone GDPR/DSGVO policy set, this can be a 2-page reference document only. Wizard should detect ISO 27701 module enabled and trim accordingly.
- **Required sections:** legal anchor (GDPR / BDSG), DPO role + contact, data-subject-rights process (link to DSR module), DPIA threshold + process (link to DPIA workflow), data-breach notification (link to incident policy + 72h DataBreach workflow), records-of-processing reference (Art. 30 RoPA).
- **Tenant inputs:** DPO name, DPO contact, supervisory authority, DSR-response SLA (default 30 days, GDPR Art. 12), DPIA threshold criteria, processor-vs-controller status per service.
- **Linked Annex A:** A.5.34, A.5.31 (Legal/statutory/regulatory requirements), A.5.32 (Intellectual property rights), A.8.11 (Data masking), A.8.12 (DLP).
- **Review cadence:** Annual + on regulatory change. NB: EU AI-Act 2026 phased applicability triggers re-review.

### 2.18 Information Security Incident Management
- **Reference:** A.5.24 *Information security incident management planning and preparation* + A.5.25 *Assessment and decision on information security events* + A.5.26 *Response to information security incidents* + A.5.27 *Learning from information security incidents* + A.5.28 *Collection of evidence*
- **Canonical name:** Information Security Incident Management Policy / **DE:** Richtlinie zum Sicherheitsvorfallsmanagement
- **Why mandatory:** ISO 27002:2022 §5.24 → "*The organization shall plan and prepare for managing information security incidents by defining, establishing and communicating information security incident management processes, roles and responsibilities.*" "Shall." Plus DORA Art. 17–23 ICT-incident reporting + NIS2 Art. 23 incident notification + GDPR Art. 33/34 data-breach reporting. Multi-regulator overlay.
- **Required sections:** incident definition + classification, reporting channels, triage SLAs per severity, response playbooks (link to runbooks), evidence-handling (chain of custody, A.5.28), regulator-notification matrix (GDPR 72h, NIS2 24h+72h+1m, DORA initial+intermediate+final), post-incident review process, lessons-learned mechanism, war-room activation.
- **Tenant inputs:** incident-response team composition, on-call rota tooling, regulators applicable (GDPR / NIS2 / DORA / sector-specific), evidence-storage location, public-comms approval authority.
- **Linked Annex A:** A.5.24–A.5.28, A.6.8 (Information security event reporting), A.5.5 (Contact with authorities), A.5.6 (Contact with special interest groups).
- **Review cadence:** Annual + after any major incident (lessons-learned mandates re-review).

### 2.19 Continuity (ICT-readiness for BC)
- **Reference:** A.5.29 *Information security during disruption* + A.5.30 *ICT readiness for business continuity*
- **Canonical name:** ICT Continuity Policy / **DE:** IKT-Kontinuitäts-Richtlinie
- **Why mandatory:** ISO 27002:2022 §5.30 → "*ICT readiness shall be planned, implemented, maintained and tested based on business continuity objectives and ICT continuity requirements.*" "Shall." Note: full BCM lives in ISO 22301 — this policy is the **InfoSec slice** linked to the wider BCM. If an ISO 22301 BC-policy already exists, this can be a 1-page link document.
- **Required sections:** scope (ICT only or full BC), RTO/RPO per system tier (link to BCM module), DR-site strategy, failover testing cadence, dependency-mapping requirement, third-party-continuity dependency.
- **Tenant inputs:** RTO/RPO per tier, DR-site type (hot / warm / cold / cloud), test cadence (default annual full-failover), dependency-on-supplier-continuity flag.
- **Linked Annex A:** A.5.29, A.5.30, A.8.13 (Backup), A.8.14 (Redundancy).
- **Review cadence:** Annual + on infrastructure change. After every BC-test, lessons must feed back.

### 2.20 Threat Intelligence
- **Reference:** A.5.7 *Threat intelligence*
- **Canonical name:** Threat Intelligence Policy / **DE:** Bedrohungsanalyse-Richtlinie
- **Why mandatory:** ISO 27002:2022 §5.7 → "*Information relating to information security threats shall be collected and analysed to produce threat intelligence.*" "Shall." NEW in 2022 (was not in 2013). Many organisations are still under-mature here — auditors are forgiving about depth, strict about existence.
- **Required sections:** intelligence sources (BSI WID, CERT-Bund / CERT-EU, ISAC membership, commercial feeds, OSINT), processing cadence, dissemination to operational teams (vuln-mgmt, IR, SOC), strategic-vs-tactical-vs-operational levels.
- **Tenant inputs:** subscribed sources, ISAC memberships, commercial-feed product, dissemination tooling.
- **Linked Annex A:** A.5.7, A.8.8 (Vulnerabilities), A.5.6 (Special interest groups), A.5.5 (Authorities).
- **Review cadence:** Annual + on geopolitical / threat-landscape change.

### 2.21 Mobile Device + Teleworking
- **Reference:** A.6.7 *Remote working* + A.7.9 *Security of off-premises assets* + A.8.1 *User endpoint devices*
- **Canonical name:** Mobile Device & Remote Working Policy / **DE:** Richtlinie für mobile Geräte und Telearbeit
- **Why mandatory:** ISO 27002:2022 §6.7 → "*A topic-specific policy on remote working shall be established that defines the conditions and restrictions for using remote working.*" Explicit "topic-specific policy" wording. §8.1 also requires endpoint-device rules.
- **Required sections:** approved working modes (office/home/anywhere), approved networks, MDM/MAM enrolment requirement, endpoint encryption, device-loss process, BYOD scope (if any), VPN/ZTNA mandate, screen-sharing/calls-from-public-spaces rules, family-shared-device rule.
- **Tenant inputs:** working modes allowed, MDM/MAM tool, BYOD allowed (yes/no), VPN tool, endpoint-encryption standard, country-restrictions (export-control).
- **Linked Annex A:** A.6.7, A.7.9, A.8.1, A.7.13 (Equipment maintenance), A.7.14 (Secure disposal/re-use of equipment).
- **Review cadence:** Annual + on working-mode change.

### 2.22 Asset Management
- **Reference:** A.5.9 *Inventory of information and other associated assets* + A.5.10 *Acceptable use* + A.5.11 *Return of assets*
- **Canonical name:** Asset Management Policy / **DE:** Asset-Management-Richtlinie
- **Why mandatory:** ISO 27002:2022 §5.9 → "*An inventory of information and other associated assets, including owners, shall be developed and maintained.*" "Shall." Owner-mandate is the key audit-trigger. Often combined with Acceptable Use, but should be separate when asset count > ~500 or when CMDB integration matters.
- **Required sections:** asset categories (information / hardware / software / services / people), inventory ownership, ownership-assignment rule (every asset has a named owner), inventory-review cadence, retirement/disposal process, integration with CMDB, label/tag scheme.
- **Tenant inputs:** CMDB tool, asset-categories list, inventory-review cadence (default 12m), asset-owner assignment SLA.
- **Linked Annex A:** A.5.9, A.5.10, A.5.11, A.7.10 (Storage media), A.7.14 (Secure disposal).
- **Review cadence:** Annual + on CMDB-tool change.

### 2.23 HR Security
- **Reference:** A.6.1 *Screening* + A.6.2 *Terms and conditions of employment* + A.6.3 *Information security awareness, education and training* + A.6.4 *Disciplinary process* + A.6.5 *Responsibilities after termination or change of employment* + A.6.6 *Confidentiality or non-disclosure agreements*
- **Canonical name:** Human Resources Security Policy / **DE:** Personalsicherheits-Richtlinie
- **Why mandatory:** ISO 27002:2022 §6.3 → "*Personnel of the organization and relevant interested parties shall receive appropriate information security awareness, education and training and regular updates of the organization's topic-specific policies …*" Plus six related controls. Universally applicable. Heavy works-council / Betriebsrat overlap in DE.
- **Required sections:** pre-employment screening (lawful in DE only with consent + role-justification), security clauses in employment contracts, awareness programme (cadence, content, evidence), role-based training matrix, disciplinary process, off-boarding (access removal, asset return), NDAs (template + retention), social-engineering-test stance.
- **Tenant inputs:** screening level per role-tier, training-platform tool, training cadence (default annual + role-based), works-council agreement reference, NDA template, off-boarding SLA (default same-day for involuntary, 1-day for voluntary).
- **Linked Annex A:** A.6.1–A.6.6, A.5.11 (Return of assets), A.5.18 (Access rights — JML).
- **Review cadence:** Annual + on HR-process change. Works-council co-determination on awareness-platform change in DE.

### 2.24 Physical Security
- **Reference:** A.7.1 *Physical security perimeters* + A.7.2 *Physical entry* + A.7.3 *Securing offices, rooms and facilities* + A.7.4 *Physical security monitoring* + A.7.5 *Protecting against physical and environmental threats* + A.7.6 *Working in secure areas* + A.7.7 *Clear desk and clear screen* + A.7.8 *Equipment siting and protection* + A.7.9–A.7.14 (off-premises, supporting utilities, cabling, equipment maintenance, removal, disposal/re-use)
- **Canonical name:** Physical & Environmental Security Policy / **DE:** Physische und umgebungsbezogene Sicherheits-Richtlinie
- **Why mandatory:** ISO 27002:2022 §7.7 → "*Clear desk rules for papers and removable storage media and clear screen rules for information processing facilities shall be defined and appropriately enforced.*" "Shall." Plus 13 related controls. Critical for offices, server rooms, data centres. If the org is fully remote-only with cloud-only infrastructure, this can be lean (1–2 pages) but cannot be N/A — home-office is still a workplace.
- **Required sections:** perimeter zoning (public / reception / office / restricted / DC), access-control mechanism per zone, visitor management, physical monitoring (CCTV — privacy-sensitive in DE!), environmental controls (fire/water/temp), clear-desk + clear-screen, secure-area working rules, cabling (data + power separation), equipment maintenance, secure disposal.
- **Tenant inputs:** site list (link to locations module), access-control mechanism (badge / biometric / key), CCTV scope, environmental-controls scope, works-council CCTV agreement, fully-remote-flag.
- **Linked Annex A:** A.7.1–A.7.14, A.5.10 (Acceptable use — clear desk overlap).
- **Review cadence:** Annual + on site change.

---

## 3. Records / Procedures Often Confused with Policies

> The wizard MUST NOT generate these as "policies" — they are mandatory ISMS artefacts but live in other modules and have different lifecycle / templating needs. Mark them in the wizard UI as "**managed elsewhere — not generated here**" with a deep-link to the responsible module.

| Artefact | Standard ref | Why it is NOT a policy | Wizard handling |
|---|---|---|---|
| **Statement of Applicability (SoA)** | Cl. 6.1.3 d | Living document, generated from Annex A applicability decisions + control-implementation status. Auto-populated by the SoA module. | Out of scope — link to SoA module. The wizard FEEDS the SoA by linking generated policies to controls. |
| **Risk Treatment Plan (RTP)** | Cl. 6.1.3 e + Cl. 6.2 | Operational plan, not a policy. Per-risk action items. | Out of scope — managed in Risk module. |
| **Risk Acceptance records** | Cl. 6.1.3 f + Cl. 8.3 | Per-risk approval record. | Out of scope — Risk module. |
| **Internal Audit Programme** | Cl. 9.2.2 | Schedule + scope per audit, not a policy. (However an *Audit Policy* governing the programme itself is good practice and listed under Top-Level coverage.) | Out of scope — Audits module. |
| **Internal Audit reports** | Cl. 9.2.2 e | Per-audit evidence. | Out of scope — Audits module. |
| **Management Review minutes** | Cl. 9.3.3 | Per-review evidence. | Out of scope — Management Review module. |
| **Risk register** | Cl. 6.1.2 | Living register. | Out of scope — Risk module. |
| **Asset inventory** | A.5.9 | Living register. The Asset Management *policy* governs the register; the register itself is data. | Out of scope — Assets module. |
| **Incident records** | A.5.25 / A.5.26 | Per-incident evidence. | Out of scope — Incident module. |
| **Records of Processing (RoPA)** | GDPR Art. 30 | Processing register, not a policy. | Out of scope — Privacy module / ISO 27701 wizard. |
| **DPIAs** | GDPR Art. 35 | Per-processing assessment. | Out of scope — DPIA workflow. |
| **Training records** | A.6.3 / Cl. 7.2 | Per-person evidence. | Out of scope — Training module. |
| **Change records** | A.8.32 | Per-change evidence. | Out of scope — Change Requests module. |
| **Backup test reports** | A.8.13 | Per-test evidence. | Out of scope — operational record. |
| **BC test reports** | A.5.30 | Per-test evidence. | Out of scope — BC Exercises module. |
| **Vulnerability scan reports** | A.8.8 | Per-scan evidence. | Out of scope — Vulnerabilities module. |
| **Supplier register / contracts** | A.5.19 | Living register + contracts. | Out of scope — Suppliers module. |
| **DORA Register of Information** | DORA Art. 28 | Regulatory register, not a policy. | Out of scope — DORA add-on. |

---

## 4. Tenant-Settings Inputs Needed

> The wizard collects these once and substitutes them across all generated policies. Group into **6 logical steps** for a digestible UX.

### Step 1 — Organisation & Scope
- Organisation legal name + commercial-register entry (`tenant.legal_name`, `tenant.register_no`)
- Trading name (if different)
- Headquarters address + locale (de_DE / en_GB)
- ISMS scope statement (Cl. 4.3) — verbatim text, max 500 chars
- Sites / locations included in scope (link to existing locations module)
- Industries / sectors (drop-down — drives sector-overlay defaults: finance → DORA, energy/transport/etc → NIS2, automotive → TISAX out-of-scope here but flagged)
- Number of employees (banding: <50 / 50–250 / 250–1000 / >1000) — drives template-richness
- ISMS context summary including climate-change consideration (Cl. 4.1/4.2 + Amd. 1:2024)

### Step 2 — Roles & Responsibilities
- CISO / ISB user + email + role-title
- DPO / Datenschutzbeauftragter user + email (mandatory if ≥20 employees handling personal data per BDSG §38, or for processors per GDPR Art. 37)
- Top-management signatory (CEO / Geschäftsführung) — name + role
- Compliance Manager / Head of GRC user
- Works-council / Betriebsrat exists (yes/no — drives DE-specific clauses in HR / Logging / Physical)
- Internal Audit lead user
- Crisis-team leader (link to BCM module)

### Step 3 — Annex A Applicability Decisions
> Drives the SoA and the body-content of multiple policies. Each decision is the binary "applicable / not applicable" + a justification field for non-applicability.

- Cloud usage (drives A.5.23 Cloud Services applicability + Network policy content)
- In-house software development (drives A.8.25–A.8.30 + Secure Dev policy)
- Outsourced software development (drives A.8.30 + Supplier policy)
- BYOD allowed (drives A.6.7 + Mobile policy)
- Remote working allowed (drives A.6.7 + Mobile policy)
- Operational technology / ICS-OT in scope (drives Network policy + Patch policy variants)
- Physical sites operated by org (drives A.7.x — if pure-cloud-pure-remote, lean version)
- Personal data processed (drives A.5.34 + Privacy policy depth)
- Special category data per GDPR Art. 9 (drives DPIA threshold)

### Step 4 — Risk & Classification Baseline
- Risk-appetite tier (1 = Risk-averse / 5 = Risk-seeking) — drives Acceptable-Risk thresholds in policies
- Data classification scheme (3-level: public / internal / confidential | 4-level: + strictly-confidential | 5-level: + secret) — drives handling matrix
- Risk-acceptance authority matrix (low → CISO, medium → Compliance Manager, high → top management)
- Risk-assessment methodology (qualitative / semi-quantitative / quantitative — link to ISO 27005)

### Step 5 — Operational Baselines
- Backup RTO/RPO per tier (3 tiers default: tier-1 1h/1h, tier-2 24h/24h, tier-3 7d/24h)
- Patch SLAs per CVSS severity (default: critical 7d / high 30d / medium 90d / low next-release)
- Cryptography baseline (BSI TR-02102 / NIST FIPS 140-3 / industry-default — drives algorithm allow-list)
- Access-review cadence (default 6 months)
- MFA scope (all-users / privileged-only / external-facing-only)
- Logging retention defaults per category (security 12m / app 90d / system 90d)
- Vulnerability-scan cadence (default monthly external, weekly internal)
- Working modes (office / hybrid / fully-remote)
- Cloud/on-prem mix percentage (drives Network + Backup + Continuity policy emphasis)

### Step 6 — Lifecycle, Approval, Communication
- Default policy review cadence (default 12 months — bounded 6–24 months)
- Default approval workflow (single approver / four-eyes / committee) — links to existing four_eyes module
- Communication channel defaults (intranet URL / training portal / email distribution list)
- External publication policy (none / extract / full)
- Locale for first generation (de_DE / en_GB / both)
- Document numbering scheme (e.g. `POL-INFOSEC-001`)

---

## 5. Hierarchy Considerations (Konzern / Subsidiary)

### 5.1 Inheritance model
- **Konzern-CISO (group level)** sets a baseline policy library marked `is_baseline = true`.
- **Tochter (subsidiary)** inherits with three modes per setting:
  1. **`inherited`** — child uses parent value as-is.
  2. **`overridden_higher`** — child sets a stricter value (allowed by default).
  3. **`overridden_lower`** — child sets a less-strict value (blocked by default; requires `Konzern-CISO` approval).
- **Stricter-only direction:** for cadence-style fields (review cadence, access-review, MFA scope), child can shorten interval / broaden scope but not lengthen / narrow. The wizard must validate the direction.

### 5.2 Override-allowed vs Override-forbidden markers
> Per setting, the parent-tenant declares an override-mode. Default values shown.

| Setting | Default override-mode | Rationale |
|---|---|---|
| Risk appetite tier | override-forbidden | Group risk culture must align (DORA Art. 6 governance) |
| Crypto baseline | override-allowed-stricter-only | Group sets minimum, child can exceed |
| Data classification scheme | override-forbidden | Cross-entity data-sharing requires unified scheme |
| Backup RTO/RPO | override-allowed-stricter-only | Subsidiaries with stricter SLAs may shorten |
| Patch SLAs | override-allowed-stricter-only | Same |
| Access-review cadence | override-allowed-stricter-only | Same |
| MFA scope | override-allowed-broader-only | Child can broaden, not narrow |
| Logging retention | override-allowed-longer-only | Child can keep longer for sector reasons (e.g. finance 10y) |
| Working modes | override-allowed | Local labour law (DE Betriebsrat) may force adjustment |
| Approval workflow | override-allowed-stricter-only | Child can require four-eyes where parent allowed single |
| Document numbering | override-allowed | Local naming-convention freedom |
| Communication channels | override-allowed | Local intranet differences |

### 5.3 Review-cadence inheritance
- Subsidiary CANNOT extend parent's review interval.
- Example: parent sets 12-months; subsidiary may set 6 or 9, never 18 or 24.
- Wizard MUST enforce this with a validator on the cadence field.

### 5.4 Audit considerations
- Konzern-Auditor (`ROLE_KONZERN_AUDITOR`) needs visibility on which child policies are inherited verbatim vs. forked. Wizard should tag generated policies with `policy.inheritance_status: inherited | forked | child-only`.
- An auditor finding at child level may force a baseline-policy update at parent level — policy-feedback loop must be documented.

---

## 6. Risks of Auto-Generated Policies

### 6.1 Auditor pushback on "template feel"
- Auditors (ISO 17021 / DAkkS) treat boilerplate-only policies as evidence of immature ISMS.
- **Mitigation:** mandatory free-text fields per policy (e.g. "specific examples in your environment", "key technologies in scope", "named tools / suppliers"). Surface these as required form fields; refuse to publish if blank.
- **Mitigation:** include tenant-specific evidence references (e.g. "see attached network diagram NW-DIAG-2026-Q2"), even if attached evidence is added later.

### 6.2 Need for tenant-specific tailoring fields
- Every policy MUST contain at least 3–5 highlighted variables that reflect the actual organisation: scope, named systems, named tooling, contact persons, cadences.
- **Recommendation:** wizard renders a preview with all variables visually highlighted (e.g. yellow-marker style). Approver must explicitly review each before sign-off.

### 6.3 Approval workflow MUST run; never auto-publish
- Generated policies enter status `draft` only. Wizard MUST NOT auto-set `published`.
- Workflow: `draft → review (Compliance Manager) → approval (Top Management) → published`.
- For top-level Cl. 5.2 policy: top-management signature is non-delegable.
- For topic-specific policies: CISO can be the approver, top management is informed (Cl. 5.1 commitment).

### 6.4 Annual review CRON
- Each generated `Document` record gets `next_review_at = now() + cadence_months`.
- Cron job: `app:check-policy-reviews` — fires notifications at T-30 days and T-0 to policy owner + CISO.
- Overdue policies (T+0 +30 days grace) must trigger an automatic risk in the risk register: "Policy X overdue review — potential nonconformity Cl. 7.5".

### 6.5 Translation-key drift
- If translation keys for policy bodies change, existing published policies must NOT change retroactively (regulatory: signed-off documents are immutable).
- **Mitigation:** version translation-keys (e.g. `policy.iso27001.access_control.v1.body`). Wizard pins version at generation time. New tenants get newest version; existing tenants see "new version available — review delta?" workflow.

### 6.6 Cross-policy consistency
- A risk-appetite tier set in tenant settings affects multiple policies. If the tier changes later, all policies referencing it must regenerate or annotate.
- **Mitigation:** policies store the tenant-setting snapshot at generation time; a settings-change triggers a "policies impacted" report and an optional bulk-regenerate workflow (still requiring approval per policy).

### 6.7 Regulatory drift
- DORA Art. 28–30 added supplier-policy clauses since Jan 2025. NIS2 added incident-reporting clauses. New regulations are detected via `ComplianceFramework` updates.
- **Mitigation:** wizard checks active modules per tenant; if DORA / NIS2 / GDPR are active, additional sections are unlocked / required. The wizard must render these conditionally.

### 6.8 GDPR / works-council co-determination (DE-only)
- HR Security, Logging, Physical Security policies in DE require Betriebsrat involvement before publication.
- **Mitigation:** wizard surfaces a "Betriebsrat-Konsultation erforderlich" warning + checkbox to confirm consultation done. Without checkbox, policy cannot be approved.

### 6.9 Multi-tenancy data leakage in templates
- Template-variable substitution must be tenant-scoped — never pull from another tenant's settings.
- **Mitigation:** generation runs inside `TenantContext`; unit-test must verify no cross-tenant variable bleed.

---

## 7. Recommendations

### 7.1 Approved-template + variable-substitution pattern
- Build a template library of ~24 policies in DE + EN.
- Templates use placeholder syntax (e.g. `{{ tenant.organisation_name }}`, `{{ tenant.crypto_baseline }}`).
- Free-form generation (LLM-style) is discouraged: regulator predictability + auditor familiarity demand stable templates. Variable substitution gives a "filled template" feel without "machine-generated text" risk.

### 7.2 Variables surface as form fields
- Each variable in the template registers itself in a manifest (e.g. `templates/policies/iso27001/access_control.manifest.yaml`).
- Wizard reads manifest → renders form fields → validates → substitutes → produces document.
- Optional / required flagging in manifest.

### 7.3 Document lifecycle
- Generated policy is a `Document` entity record with status workflow: `Draft → Review → Approved → Published → (eventually) Retired`.
- Each transition requires a workflow step (use existing workflow engine).
- Approved policies are immutable — changes require a new version.
- Retired policies stay in audit log per Cl. 7.5 retention rules (typically 3–7 years).

### 7.4 Translation-key strategy
- Each policy body lives under a deterministic key path: `policy.iso27001.<topic>.v<n>.<section>`.
- Wizard generates Document records pointing at the translation-keys — both DE and EN versions exist automatically.
- Body content per locale is stored as Twig-rendered HTML + Markdown source for diffing.

### 7.5 Auto-link to SoA
- Each policy declaration in the manifest lists `linked_controls: [A.5.10, A.5.15, …]`.
- On generation, the wizard creates `Policy ↔ Control` link records. SoA module displays "covered by Policy X" badge per control.
- If a control has no covering policy + no compensating measure, SoA shows a "**gap**" indicator.

### 7.6 Incremental wizard, not single-shot
- Step-by-step UX (6 steps as per §4) with save-and-resume.
- Each step validates inputs before allowing next.
- Step 7 = preview (read-only render of every policy with variables highlighted), Step 8 = approval-routing setup, Step 9 = generation + workflow kick-off.
- Total: 7 steps user sees, 2 hidden technical steps.

### 7.7 Avoid auto-publishing
- Never set `published_at` automatically. Always require human sign-off.
- Document the user who triggered generation, the approver chain, and the timestamps. Audit trail maps directly to Cl. 7.5.3 c.

### 7.8 DORA / NIS2 / GDPR / ISO 27701 add-ons
- Treat sector overlays as additive sections, not separate policies.
- Example: DORA-active tenant gets enriched sections in Supplier-Policy (Art. 28 register-of-information; Art. 30 contractual clauses), Incident-Policy (Art. 17–23 reporting cascade), Continuity-Policy (Art. 11–14 ICT-resilience).
- Manifest declares: `sector_overlays: { dora: { sections_added: [...] }, nis2: { ... } }`.

### 7.9 Re-use existing modules — Data-Reuse principle
- DPO / DSR / DPIA / 72h DataBreach workflow: do **not** duplicate. The Privacy policy references them.
- Asset inventory: do **not** duplicate. The Asset-Management policy references the existing module.
- Risk register: do **not** duplicate. The HR / Supplier / SecDev policies reference it.
- Workflow auto-progression: tie policy-approval to existing `WorkflowAutoProgressionService` so policy-status field changes auto-progress workflows.

### 7.10 Compliance-Manager efficiency gain (Konzern-perspective)
- One canonical policy generation = N tenants benefit (if Konzern model used).
- Cross-framework re-use: a policy generated for ISO 27001 maps to identical-or-supersetting controls in NIS2, DORA, ISO 27701, BSI IT-Grundschutz baustein chains.
- Recommendation: store policy-to-control links as `(framework_id, control_id)` tuples — enables one-click re-mapping when adding a framework later.

### 7.11 Skip ISO 27002:2022 redundancy
- Do not generate a separate "ISO 27002 policy." ISO 27002 is non-certifiable guidance — Annex A is enough.
- Auditors check Annex A control coverage, not ISO 27002 directly.

### 7.12 Output formats
- Primary: Twig-rendered HTML for in-app viewing.
- Secondary: PDF export with cover page (org logo + ISMS scope + version + approver signatures + classification footer).
- Tertiary: DOCX export (for organisations that maintain official documents in their existing DMS).

### 7.13 Climate-change wording (Amd. 1:2024)
- ISO 27001:2022/Amd 1:2024 added climate-change consideration to Cl. 4.1 + 4.2.
- Top-level policy must mention climate-change as an external/internal issue if material to the organisation.
- Wizard surfaces a yes/no field in Step 1: "Is climate change material to your ISMS context?" — if yes, dedicated paragraph in top-level policy + a context-statement.
- This is being audited from 2026 onwards — do not skip.

### 7.14 Don't fabricate regulatory references
- Stick to real clauses + real Annex A IDs.
- Use `ISO/IEC 27001:2022` (not 2013, 2017-amended-2022 also wrong) and `ISO/IEC 27002:2022` for the underpinning control guidance.
- For DORA: Regulation (EU) 2022/2554. For NIS2: Directive (EU) 2022/2555. For GDPR: Regulation (EU) 2016/679.

---

## Appendix A — Policy Generation Order (Dependency Graph)

When the wizard generates the policy set, order matters because policies reference each other:

1. Top-level Information Security Policy (Cl. 5.2) — references everything below
2. Information Classification & Handling — referenced by 4–24
3. Asset Management — referenced by 4–24
4. HR Security — referenced by Acceptable Use
5. Acceptable Use — references 1–4
6. Access Control + Identity Management + Authentication Information — 3 cross-coupled
7. Cryptography — referenced by Backup, Network, Transfer, Secure Dev
8. Information Transfer — references 7
9. Backup — references 7
10. Logging — references Identity (5)
11. Physical Security
12. Mobile Device + Teleworking
13. Network Security — references 7
14. Patch / Vulnerability Management — references Threat Intelligence
15. Malware
16. Secure Configuration
17. Secure Development — references 7, 14, Supplier
18. Supplier Relationships — referenced by 17
19. Project Management Security
20. Privacy / PII (or skip if ISO 27701 module enabled)
21. Threat Intelligence — referenced by 14
22. Incident Management — references everything (last detector chain)
23. ICT Continuity — references Backup, Incident
24. (Optional) sector-specific overlays applied to 17, 18, 22, 23

Policies 5–24 should reference Policy 1 by ID + version in their introduction.

---

## Appendix B — Quick Cross-Reference Matrix (which policy covers which control)

| Annex A control | Primary policy | Secondary mention |
|---|---|---|
| A.5.1 (Policies for InfoSec) | Top-level | All others reference it |
| A.5.2 (Roles and responsibilities) | Top-level | HR Security |
| A.5.3 (Segregation of duties) | Access Control | HR Security |
| A.5.4 (Management responsibilities) | Top-level | — |
| A.5.5 (Contact with authorities) | Incident Mgmt | Threat Intelligence |
| A.5.6 (Special interest groups) | Threat Intelligence | Incident Mgmt |
| A.5.7 (Threat intelligence) | Threat Intelligence | — |
| A.5.8 (InfoSec in PM) | Project Mgmt Security | — |
| A.5.9 (Asset inventory) | Asset Mgmt | — |
| A.5.10 (Acceptable use) | Acceptable Use | Asset Mgmt |
| A.5.11 (Return of assets) | HR Security | Asset Mgmt |
| A.5.12 (Classification) | Classification & Handling | — |
| A.5.13 (Labelling) | Classification & Handling | — |
| A.5.14 (Information transfer) | Information Transfer | Classification & Handling |
| A.5.15 (Access control) | Access Control | — |
| A.5.16 (Identity Mgmt) | Identity Management | Access Control |
| A.5.17 (Authentication info) | Authentication Information | Access Control |
| A.5.18 (Access rights) | Access Control | HR Security |
| A.5.19–A.5.22 (Suppliers) | Supplier Relationships | — |
| A.5.23 (Cloud services) | Supplier Relationships | Network Security |
| A.5.24–A.5.28 (Incidents) | Incident Mgmt | — |
| A.5.29 (InfoSec during disruption) | ICT Continuity | Incident Mgmt |
| A.5.30 (ICT readiness for BC) | ICT Continuity | — |
| A.5.31 (Legal/statutory) | Top-level | Privacy |
| A.5.32 (IPR) | Top-level | Privacy |
| A.5.33 (Records protection) | Classification & Handling | Backup |
| A.5.34 (Privacy / PII) | Privacy | Classification & Handling |
| A.5.35 (Independent review of InfoSec) | Top-level | (Audit Programme) |
| A.5.36 (Compliance with policies) | Top-level | All others |
| A.5.37 (Documented operating procedures) | Top-level | Operational policies |
| A.6.1–A.6.6 (HR) | HR Security | — |
| A.6.7 (Remote working) | Mobile & Remote Working | HR Security |
| A.6.8 (Event reporting) | Incident Mgmt | HR Security |
| A.7.1–A.7.14 (Physical) | Physical Security | — |
| A.8.1 (User endpoint devices) | Mobile & Remote Working | Acceptable Use |
| A.8.2–A.8.5 (Access mechanisms) | Access Control | Authentication Information |
| A.8.6 (Capacity mgmt) | Top-level (operational) | ICT Continuity |
| A.8.7 (Malware) | Malware | — |
| A.8.8 (Vulnerabilities) | Vulnerability & Patch Mgmt | — |
| A.8.9 (Configuration) | Secure Configuration | — |
| A.8.10 (Information deletion) | Classification & Handling | Privacy |
| A.8.11 (Data masking) | Privacy | Secure Development |
| A.8.12 (DLP) | Classification & Handling | Privacy |
| A.8.13 (Backup) | Backup | ICT Continuity |
| A.8.14 (Redundancy) | ICT Continuity | Backup |
| A.8.15 (Logging) | Logging & Monitoring | — |
| A.8.16 (Monitoring) | Logging & Monitoring | Network Security |
| A.8.17 (Clock sync) | Logging & Monitoring | — |
| A.8.18 (Privileged utility) | Access Control | Secure Configuration |
| A.8.19 (Software install on op systems) | Secure Configuration | Acceptable Use |
| A.8.20–A.8.23 (Network) | Network Security | — |
| A.8.24 (Cryptography) | Cryptography | — |
| A.8.25–A.8.30 (SecDev) | Secure Development | — |
| A.8.31 (Dev/test/prod separation) | Secure Development | — |
| A.8.32 (Change mgmt) | Top-level (operational) | Secure Configuration, SecDev |
| A.8.33 (Test information) | Secure Development | Privacy |
| A.8.34 (Audit testing protection) | Top-level | (Audit Programme) |

> **Coverage check:** with the 24 topic-specific policies + the top-level policy, all 93 Annex A controls have at least one primary policy reference. Two controls (A.8.6 capacity management + A.8.32 change management) are placeholdered to the top-level policy as operational appendices — auditors typically accept this for organisations under ~250 employees; larger orgs should split out an Operations Policy.

---

## Appendix C — Wording Snippets the Auditor Looks For

These exact phrases (or equivalent) should appear verbatim in generated policies. The wizard's content-quality validator should require their presence.

| Policy | Mandatory phrase |
|---|---|
| Top-level | "*This policy is appropriate to the purpose of the organisation*" + "*continual improvement of the ISMS*" |
| Acceptable Use | "*Rules for the acceptable use of information and other associated assets*" |
| Access Control | "*Rules to control physical and logical access to information and other associated assets shall be established and implemented based on business and information security requirements*" |
| Cryptography | "*Rules for the effective use of cryptography, including cryptographic key management*" |
| Backup | "*Backup copies … shall be maintained and regularly tested*" |
| Logging | "*Logs that record activities, exceptions, faults and other relevant events shall be produced, stored, protected and analysed*" |
| Vulnerabilities | "*Information about technical vulnerabilities … obtained, … evaluated and appropriate measures taken*" |
| Configuration | "*Configurations … shall be established, documented, implemented, monitored and reviewed*" |
| Network | "*Networks and network devices shall be secured, managed and controlled*" |
| Secure Dev | "*Rules for the secure development of software and systems shall be established and applied*" |
| Suppliers | "*Processes and procedures … manage the information security risks associated with the use of suppliers' products or services*" |
| HR | "*Personnel … shall receive appropriate information security awareness, education and training*" |
| Privacy | "*The organisation shall identify and meet the requirements regarding the preservation of privacy and protection of PII*" |
| Incident | "*The organisation shall plan and prepare for managing information security incidents*" |
| Continuity | "*ICT readiness shall be planned, implemented, maintained and tested based on business continuity objectives*" |
| Threat Intel | "*Information relating to information security threats shall be collected and analysed to produce threat intelligence*" |
| Remote Working | "*A topic-specific policy on remote working … defines the conditions and restrictions*" |
| Classification | "*Information should be classified … in accordance with the information security needs of the organisation*" |
| Clear Desk | "*Clear desk rules … and clear screen rules … shall be defined and appropriately enforced*" |

The presence of these phrases makes the policies "audit-ready" on first reading. Their absence is the #1 reason for nonconformities at Stage 1 audits.

---

## Appendix D — Out-of-Scope Notes for the Wizard

Items the wizard SHOULD NOT attempt:
- Generating the SoA (separate module, fed by wizard output)
- Generating risk-treatment-plan entries (separate module)
- Auto-classifying assets (asset-management module)
- Performing gap analyses against other frameworks (compliance-wizard handles)
- Producing certification-application paperwork (out of product scope)
- Calculating risk values (risk-management module)
- Issuing training certificates (training module)
- Maintaining ROPA (privacy module / ISO 27701 wizard)

---

*End of ISMS-Specialist input. Hand-off to compliance-manager-persona for cross-framework re-use mapping (DORA / NIS2 / ISO 27701) and to ux-specialist for wizard-flow design.*
