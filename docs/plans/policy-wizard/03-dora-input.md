# DORA Addon — Policy Mandates over ISO 27001 baseline

> Consultative input from the Risk-Management-Specialist persona.
> Target audience: Policy-Wizard implementer. Scope: ESA Tier 2 deltas
> on top of an existing ISO 27001 + (optional) BSI baseline.
> Effective regulatory date: **17 January 2025**.

---

## 1. Scope

### Regulatory Anchor

Regulation **(EU) 2022/2554** ("DORA" — Digital Operational Resilience Act),
in force since **16 January 2023**, applicable from **17 January 2025**.
Accompanying directive: **(EU) 2022/2556** (amending sectoral directives
to align with DORA terminology).

### Who is in scope (DORA Art. 2)

20 categories of financial entities, covering — non-exhaustive list:

- Credit institutions (banks)
- Payment institutions, electronic money institutions, account information service providers (AISP)
- Investment firms, central securities depositories (CSDs), central counterparties (CCPs)
- Trading venues, trade repositories
- Managers of alternative investment funds (AIFM), UCITS management companies
- Insurance and reinsurance undertakings, insurance intermediaries
- Institutions for occupational retirement provision (IORPs)
- Credit rating agencies, statutory auditors and audit firms
- Administrators of critical benchmarks
- Crowdfunding service providers
- Securitisation repositories
- **Crypto-asset service providers (CASPs)** authorised under MiCA
- **Critical ICT third-party service providers (CTPPs)** — directly supervised by ESAs (Art. 31)

### Explicit exemptions (Art. 2.3)

- AIFMs below the de minimis thresholds in Directive 2011/61/EU Art. 3.2
- Insurance and reinsurance undertakings classified as "small and non-complex"
- IORPs operating fewer than 15 plans
- Postal giro institutions (Art. 2.5.e of Directive 2013/36/EU)
- Microenterprises benefit from a **simplified ICT-RMF** (Art. 16) — wizard MUST
  expose this as a tenant-setting toggle.

### ICT Risk Management Framework (Art. 6) — top-level requirement

The ICT-RMF must include documented strategies, policies, procedures,
ICT protocols and tools to:

- Protect all information and ICT assets
- Identify and classify ICT-related functions
- Implement backup and restoration policies and procedures
- Provide for learning and evolving (post-incident lessons learned)
- Implement crisis communication plans

### DORA Tier 2 framework

ESAs (EBA, ESMA, EIOPA) develop **Regulatory Technical Standards (RTS)** and
**Implementing Technical Standards (ITS)**. Key Tier 2 instruments:

| Designation | Topic | Status |
|---|---|---|
| **Commission Delegated Regulation (EU) 2024/1773** | RTS specifying ICT risk management framework + simplified ICT-RMF (Art. 15 + Art. 16.3) | Adopted Jun 2024 |
| **Commission Delegated Regulation (EU) 2024/1774** | RTS on classification criteria for ICT-related incidents and cyber threats (Art. 18.3) | Adopted Jun 2024 |
| **Commission Delegated Regulation (EU) 2024/1772** | RTS on criteria for classification of major ICT-related incidents (Art. 18.3 — thresholds) | Adopted Jun 2024 |
| **Commission Implementing Regulation (EU) 2024/2956** | ITS on Register of Information templates (Art. 28.9) | Adopted Nov 2024 |
| **Commission Delegated Regulation (EU) 2024/1505** | RTS on criteria for designation of Critical ICT Third-Party Providers (Art. 31.6) | Adopted May 2024 |
| **Final draft RTS on subcontracting** (JC 2024 53) | Conditions to subcontract critical/important functions (Art. 30.5) — Commission rejected first draft Jul 2024, final adoption pending | Open in 2026 |
| **Final draft RTS on TLPT** (JC 2024 29) | Threat-Led Penetration Testing methodology (Art. 26.11) — TIBER-EU aligned | Adopted Jul 2024 |
| **ITS on incident reporting templates** (JC 2024 33) | Standard forms + timelines (Art. 20) | Adopted 2024 |
| **RTS on aggregated costs of major ICT incidents** (Art. 11.11) | Reporting format | Adopted 2024 |
| **Joint Guidelines on oversight cooperation** (JC GL 2024 04) | ESA cooperation under DORA | Adopted 2024 |

The wizard MUST cite these by their published designation in generated
policy preambles ("This policy is established pursuant to Regulation
(EU) 2022/2554 Art. 9, in conjunction with Commission Delegated Regulation
(EU) 2024/1773 Art. X").

---

## 2. Mandatory ICT-Risk-Mgmt Policies (Art. 5–15)

> Each policy below MUST be approved by the **management body** (Art. 5.2 —
> "ultimate responsibility"), reviewed at least annually (Art. 6.5), and
> versioned. The wizard MUST default `approval_body = "Management Body"`
> and `review_cadence = "annual + ad hoc upon material change"`.

### 2.1 ICT Risk Management Framework Policy

- **Article:** DORA Art. 6 + Art. 6.8; RTS (EU) 2024/1773 Art. 1–3
- **Required content sections:**
  - Governance and accountability (link to Art. 5 management body responsibility)
  - Scope (all ICT systems, networks, third parties)
  - Risk identification, classification, prioritisation
  - Risk treatment and tolerance link
  - Continuous improvement loop (PDCA / "learning and evolving" loop, Art. 13)
  - Internal-audit assurance (Art. 6.6)
  - Annual review by management body
- **Tenant inputs:**
  - Entity name, legal form, NCA, sector
  - "Significant" status (Y/N — drives audit cadence + TLPT)
  - Microenterprise (Y/N — applies simplified RMF Art. 16)
- **ISO 27001 overlap:** EXTENDS Clause 4–6 + Annex **A.5.1** (information
  security policies)
- **Approval body:** Management Body
- **Review cadence:** at least annually + after major incidents (Art. 6.5)

### 2.2 ICT Risk Tolerance Statement

- **Article:** DORA Art. 6.8.b; RTS (EU) 2024/1773 Art. 3.2
- **Required content:**
  - Quantitative thresholds (RTO, RPO, max-tolerable-downtime per critical/important function)
  - Qualitative tolerance (reputational, regulatory)
  - Linkage to risk-appetite of the overall enterprise
- **Tenant inputs:** RTO/RPO defaults per function tier; reputational tolerance bands
- **ISO 27001 overlap:** EXTENDS existing **Risk-Appetite-Statement**
  (no Annex A clause — typically standalone document)
- **Approval body:** Management Body (explicit in Art. 6.8)
- **Review cadence:** annually

### 2.3 ICT Asset Management Policy

- **Article:** DORA Art. 8.1, Art. 8.4; RTS (EU) 2024/1773 Art. 4
- **Required content:**
  - Inventory of all information and ICT assets (incl. virtual / cloud assets)
  - Records of dependencies between ICT assets
  - Records of dependencies on ICT third-party service providers
  - Asset-classification taxonomy
  - Lifecycle management (acquisition → disposal)
- **Tenant inputs:** asset-classification scheme (default: confidentiality,
  integrity, availability tiers); third-party-link mandatory Y/N
- **ISO 27001 overlap:** EXTENDS **A.5.9** (Inventory of information and other
  associated assets) + **A.5.10** (Acceptable use)
- **Approval body:** CISO (delegated from management body)
- **Review cadence:** annually + after material change

### 2.4 Identification and Classification Policy

- **Article:** DORA Art. 8.1–8.3; RTS (EU) 2024/1773 Art. 5
- **Required content:**
  - Identification of all business functions, processes, supporting ICT assets
  - Classification of ICT-related functions as **critical or important** (CIF)
  - Mapping critical functions ↔ supporting ICT assets ↔ third-party providers
- **Tenant inputs:** definition of "critical function" (default: per
  MiFID II Art. 16.5 / CRR Art. 312); CIF list import
- **ISO 27001 overlap:** EXTENDS **A.5.12** (Classification of information)
  — but DORA classifies *functions*, not just data
- **Approval body:** CISO + Business-Continuity-Manager
- **Review cadence:** annually + upon new product/service launch

### 2.5 ICT Operations Security Policy

- **Article:** DORA Art. 9.2, Art. 9.3; RTS (EU) 2024/1773 Art. 9–11
- **Required content:**
  - Capacity and performance management
  - Change management (incl. emergency changes)
  - Patch and vulnerability management (link to Art. 10)
  - Logging and monitoring
- **Tenant inputs:** patch-SLA per criticality tier; change-advisory-board roster
- **ISO 27001 overlap:** EXTENDS **A.8.6** (Capacity), **A.8.8** (Vulnerabilities),
  **A.8.9** (Configuration), **A.8.15** (Logging), **A.8.32** (Change management)
- **Approval body:** CISO
- **Review cadence:** annually

### 2.6 Network Security Policy

- **Article:** DORA Art. 9.4.b–c; RTS (EU) 2024/1773 Art. 13
- **Required content:**
  - Network segregation (incl. critical-function isolation)
  - Filtering, firewalls, IDS/IPS
  - Encryption of data-in-transit
  - Wireless network restrictions
  - Remote-access controls (VPN, ZTNA)
- **Tenant inputs:** segmentation depth; remote-access tooling
- **ISO 27001 overlap:** REPLACES (stricter) **A.8.20** (Networks security),
  **A.8.21** (Security of network services), **A.8.22** (Segregation of networks),
  **A.8.23** (Web filtering)
- **Approval body:** CISO
- **Review cadence:** annually

### 2.7 Cryptography Policy

- **Article:** DORA Art. 9.4.b; RTS (EU) 2024/1773 Art. 6–8
- **Required content:**
  - Approved algorithms and key lengths (NIST SP 800-131A, BSI TR-02102 alignment)
  - Key management lifecycle (generation → archival → destruction)
  - **Crypto-agility plan** (DORA-specific — preparation for post-quantum migration, Art. 9.4.b RTS Art. 7)
  - Use of HSMs / KMS
- **Tenant inputs:** preferred algorithm set; HSM availability; PQC roadmap horizon
- **ISO 27001 overlap:** EXTENDS **A.8.24** (Use of cryptography)
  — **DORA delta:** explicit crypto-agility / post-quantum readiness statement
- **Approval body:** CISO
- **Review cadence:** annually + upon NIST/BSI algorithm-deprecation announcement

### 2.8 Physical and Environmental Security Policy

- **Article:** DORA Art. 9.4.b–c; RTS (EU) 2024/1773 Art. 18
- **Required content:**
  - Data-centre site requirements (Tier-3+ recommended)
  - Environmental controls (fire, flood, HVAC, power)
  - Physical access management
  - Geographic separation of primary + secondary sites
- **Tenant inputs:** primary site, secondary site, geo-distance
- **ISO 27001 overlap:** EXTENDS **A.7.1–A.7.14** (Physical controls)
- **Approval body:** Facility Manager + CISO
- **Review cadence:** annually

### 2.9 ICT Project Management Policy

- **Article:** DORA Art. 9.4.f; RTS (EU) 2024/1773 Art. 15
- **Required content:**
  - Project lifecycle (initiation → closure)
  - Security-by-design checkpoints
  - Risk-assessment gates per stage-gate
  - Post-implementation review
- **Tenant inputs:** project methodology (Waterfall / Agile / Scaled-Agile);
  stage-gate template
- **ISO 27001 overlap:** EXTENDS **A.5.8** (Information security in project management)
- **Approval body:** PMO + CISO
- **Review cadence:** annually

### 2.10 Acquisition / Development / Maintenance of ICT Systems Policy

- **Article:** DORA Art. 9.4.g; RTS (EU) 2024/1773 Art. 16
- **Required content:**
  - Secure-development lifecycle (SDLC)
  - Source-code review and code-signing
  - Test environments separated from production
  - Acceptance testing (incl. security testing)
  - Maintenance / EOL handling
- **Tenant inputs:** SDLC framework (e.g. OWASP SAMM, BSIMM, NIST SSDF)
- **ISO 27001 overlap:** EXTENDS **A.8.25** (Secure development lifecycle),
  **A.8.27** (Secure system architecture), **A.8.28** (Secure coding),
  **A.8.29** (Security testing), **A.8.30** (Outsourced development),
  **A.8.31** (Separation of dev/test/prod)
- **Approval body:** Head of Development + CISO
- **Review cadence:** annually

### 2.11 Detection of Anomalous Activities Policy

- **Article:** DORA Art. 10.1–10.3; RTS (EU) 2024/1773 Art. 23
- **Required content:**
  - SIEM / SOC operations
  - Threshold-based alerting + behavioural anomaly detection
  - Multiple layers of defence (network, host, application)
  - Alert triage roles (L1/L2/L3)
- **Tenant inputs:** SIEM tooling; SOC operating-model (in-house / hybrid / MSSP);
  alert-thresholds per asset tier
- **ISO 27001 overlap:** EXTENDS **A.8.16** (Monitoring activities),
  **A.5.7** (Threat intelligence)
- **Approval body:** CISO
- **Review cadence:** annually + after major incident

### 2.12 ICT Response and Recovery Policy

- **Article:** DORA Art. 11; RTS (EU) 2024/1773 Art. 24–26
- **Required content:**
  - Incident-response plan triggered by detection (Art. 10)
  - **ICT Business Continuity Policy** (Art. 11.1) — DORA-specific
  - **ICT Response and Recovery Plans** per critical/important function
  - Crisis-communication procedures (link to Art. 14)
  - Annual testing requirement (Art. 11.6) + after material changes
- **Tenant inputs:** RTO/RPO per function (from §2.2);
  crisis-management-team roster
- **ISO 27001 overlap:** EXTENDS **A.5.29** (Information security during disruption),
  **A.5.30** (ICT readiness for business continuity)
  — **DORA delta:** mandatory annual testing including scenario-testing
  switch-over to secondary site (Art. 11.6.b)
- **Approval body:** Management Body + CISO + BCM
- **Review cadence:** annually + after each test cycle

### 2.13 Backup Policy and Procedures

- **Article:** DORA Art. 12.1–12.4; RTS (EU) 2024/1773 Art. 26–28
- **Required content:**
  - Backup scope, frequency, retention
  - **Restoration testing** (DORA: documented evidence per Art. 12.2)
  - Geographic separation of backup site (Art. 12.3 — "physically and logically segregated")
  - Backup integrity verification
- **Tenant inputs:** backup tooling; retention defaults per data-class;
  immutability (WORM / object-lock) Y/N
- **ISO 27001 overlap:** EXTENDS **A.8.13** (Information backup)
  — **DORA delta:** explicit physical/logical segregation + restoration evidence
- **Approval body:** CISO
- **Review cadence:** annually

### 2.14 Learning and Evolving Policy

- **Article:** DORA Art. 13.1–13.6; RTS (EU) 2024/1773 Art. 29
- **Required content:**
  - Lessons-learned process (post-incident)
  - Threat-intelligence ingestion and review (Art. 13.3)
  - **ICT security awareness programmes** (Art. 13.6 — mandatory for staff incl. management body)
  - Continuous improvement KPIs
- **Tenant inputs:** threat-intelligence feeds; awareness-training cadence;
  management-body training cadence
- **ISO 27001 overlap:** EXTENDS **A.6.3** (Information security awareness, education, training),
  **A.5.27** (Learning from incidents)
  — **DORA delta:** mandatory training for *management body*
- **Approval body:** CISO + HR
- **Review cadence:** annually

### 2.15 Communication Policy on ICT-related Incidents

- **Article:** DORA Art. 14.1–14.3
- **Required content:**
  - Internal communication ladder (escalation tree)
  - External communication: clients, counterparties, public, media
  - Designated **single point of contact** for crisis communication
  - Coordination with NCA notifications (Art. 19)
- **Tenant inputs:** crisis-spokesperson; client-notification templates;
  media-policy gate
- **ISO 27001 overlap:** EXTENDS **A.5.5** (Contact with authorities),
  **A.5.6** (Contact with special interest groups)
  — **DORA delta:** explicit client + counterparty notification under Art. 14.2
- **Approval body:** Management Body
- **Review cadence:** annually

---

## 3. ICT-related Incident Mgmt + Reporting Policy (Art. 17–23)

### 3.1 Article anchors

- **Art. 17** — ICT-related incident management process
- **Art. 18** — Classification of ICT-related incidents and cyber threats
- **Art. 19** — Reporting of major ICT-related incidents to competent authority
- **Art. 23** — Reporting of significant cyber threats (voluntary)

### 3.2 Classification thresholds (Commission Delegated Reg. (EU) 2024/1772)

An incident is **major** if it meets the threshold on:

- **Clients, financial counterparts, transactions** affected (relative + absolute)
- **Reputational impact** (media coverage, complaints)
- **Duration and service downtime** (≥ 24h or critical-function unavailable)
- **Geographic spread** (≥ 2 EU member states)
- **Data losses** (incl. confidentiality / integrity / availability of data)
- **Economic impact** (gross direct + indirect cost ≥ EUR 100k)
- **Critical services affected** (yes/no — auto-major if yes)

If two of the lower-criticality criteria + one of the higher criteria
(or alternative thresholds per RTS Art. 8) are met → **major**.

**Significant cyber threat** (Art. 23) classification per RTS Art. 11.

### 3.3 Notification deadlines (RTS / ITS on incident reporting)

| Stage | Deadline | Content |
|---|---|---|
| **Initial notification** | as soon as possible, **at most 4 hours from classification as major and 24h from awareness** | basic facts, classification rationale |
| **Intermediate report** | **72 hours from initial notification**, then weekly until activity normal | impact analysis, root-cause hypothesis, mitigation |
| **Final report** | **1 month from initial notification** | full root-cause analysis, lessons learned, cost |

> Note: deadlines are operative from the moment the incident is **classified as major**,
> not from the moment of awareness. The RTS specifies the 4h+24h dual clock.

### 3.4 Tenant-settings inputs

- Competent authority (BaFin, BMF, EZB/SSM, EIOPA, ESMA-direct for CTPPs, etc.)
- Sector and entity-type (drives reporting template selection)
- Reporting channel (BaFin MVP, EBA Reporting Hub, etc.)
- Default classification thresholds (override-able per tenant)
- 24/7 incident-coordinator contact

### 3.5 Form integration with existing Incident module

The wizard generates a policy that **references** but does not duplicate
the Incident module. Instead it MUST:

- Add a "DORA-classification" panel to existing Incident form (severity + 7 RTS criteria)
- Auto-set notification-required flag when major thresholds met
- Trigger workflow `dora-incident-major` (analogous to existing `data-breach`)
- Generate ITS-compliant XML/JSON exports for NCA submission
- Cross-link to existing Data-Breach workflow if Art. 33 GDPR also triggers

---

## 4. Digital Operational Resilience Testing Policy (Art. 24–27)

### 4.1 General testing programme (Art. 24–25)

- Testing of all critical ICT systems and applications **at least annually**
- Vulnerability assessments, scenario-based testing, compatibility testing,
  performance testing, end-to-end testing, penetration testing
- Independence of testers (Art. 24.5 — separation of duties or external)
- Remediation tracking of all findings

### 4.2 Threat-Led Penetration Testing — TLPT (Art. 26–27)

- Scope: financial entities **identified by the NCA** as significant from
  an ICT perspective (criteria in RTS on TLPT)
- Frequency: **at least every 3 years** (Art. 26.1)
- Methodology: aligned with **TIBER-EU** framework
- Internal vs external testers: external mandatory for first cycle; subsequent
  cycles permit internal only if internal Red-Team meets RTS independence
  criteria (Art. 27.2)
- Live-production testing required (Art. 26.2.b)
- Inclusion of critical ICT third-party providers (Art. 26.3)
- TLPT Authority within NCA validates scope and methodology

### 4.3 Output documents

- **Resilience Testing Programme** (multi-year plan)
- **Annual Test Plan**
- **Test Reports** (one per test, evidence retained)
- **Remediation Plans** linked to Risk module
- **TLPT Attestation** issued by NCA upon successful completion (Art. 26.7)

### 4.4 Tenant inputs

- Significance Y/N (drives TLPT applicability)
- Last TLPT date (drives next-due reminder)
- Internal Red-Team available Y/N
- TIBER-EU national-team contact (e.g. Bundesbank for DE)

### 4.5 ISO 27001 overlap

- EXTENDS **A.8.29** (Security testing in development and acceptance)
- EXTENDS **A.8.34** (Protection of information systems during audit testing)
- **DORA delta:** TLPT is materially stricter than ISO penetration-testing
  expectations — separate document, NEW

---

## 5. Third-Party ICT Risk Mgmt Policy (Art. 28–30)

### 5.1 ICT Third-Party Risk Strategy (Art. 28.2)

- **Article:** DORA Art. 28; RTS (EU) 2024/1773 Art. 30 (general),
  pending RTS on subcontracting (Art. 30.5)
- **Required content:**
  - Multi-vendor and concentration policy
  - Pre-contractual due diligence (financial soundness, security maturity,
    incident history, sub-processor map)
  - Risk-assessment per provider tier (critical/important vs other)
  - Termination and exit triggers
- **Tenant inputs:** concentration thresholds (% of CIF per provider);
  preferred-supplier registry
- **ISO 27001 overlap:** EXTENDS **A.5.19–A.5.22** (Supplier relationships),
  **A.5.23** (Cloud services)
- **Approval body:** Management Body (Art. 28.2 — explicit)
- **Review cadence:** annually

### 5.2 Mandatory contractual clauses (Art. 30) — 14 clauses

These are **contract clauses**, not a policy. The wizard should generate a
**Standard Contractual Annex** (DORA-Annex), not a separate policy document.
For contracts on **critical or important functions**, additional clauses apply
(Art. 30.3).

| # | Clause topic (Art. 30.2 — all contracts) |
|---|---|
| 1 | Clear and complete description of services |
| 2 | Locations (regions/countries) where services and data are processed |
| 3 | Provisions on availability, authenticity, integrity, confidentiality of data |
| 4 | Provisions on data access, recovery, return on termination |
| 5 | Service-level descriptions and SLAs (incl. updates) |
| 6 | Provider obligation to assist in ICT incident at no extra cost or at agreed cost |
| 7 | Provider cooperation with NCAs |
| 8 | Termination rights and minimum notice periods |
| 9 | Conditions for participation in awareness programmes and training |

| # | Additional clauses (Art. 30.3 — CIF contracts only) |
|---|---|
| 10 | Full SLA descriptions including precise quantitative + qualitative performance targets |
| 11 | Notice periods and reporting obligations to financial entity (incl. material changes) |
| 12 | Provider obligation to implement and test BCP and security measures |
| 13 | Obligation to participate in TLPT and operational-resilience testing (Art. 26.3) |
| 14 | Right of access, inspection, audit (incl. unrestricted on-site audit) |

> **Wizard implication:** generate a "DORA-Compliant Contract Addendum" template,
> NOT a policy. Cross-link to the supplier register.

### 5.3 Concentration risk policy

- **Article:** Art. 29.1
- **Required content:**
  - Methodology to assess concentration at provider, sub-provider, geographic level
  - Tolerance thresholds (e.g. ≤ 30% of CIFs per single provider)
  - Mitigation actions (multi-vendor, escrow, second-source)
- **Tenant inputs:** thresholds; aggregation taxonomy (legal entity / group / cloud-region)
- **ISO 27001 overlap:** NEW (no Annex A equivalent)

### 5.4 Exit strategy policy

- **Article:** Art. 28.8
- **Required content:**
  - Exit-trigger conditions (provider failure, regulatory order, performance breach)
  - Documented transition plan per CIF provider
  - Annual testing of exit plan (table-top minimum)
  - Data extraction and re-insertion procedures
- **Tenant inputs:** exit-test cadence; alternative-provider shortlist per CIF
- **ISO 27001 overlap:** EXTENDS **A.5.22** (Monitoring, review and change
  management of supplier services)
  — **DORA delta:** explicit annual exit-plan testing

### 5.5 Subcontracting governance policy

- **Article:** Art. 30.2.a + pending RTS on subcontracting (JC 2024 53)
- **Required content:**
  - Notification process for new sub-providers
  - Approval gate for sub-providers in CIF chain
  - Map of full sub-processor chain
- **Tenant inputs:** approval workflow; sub-processor map source

### 5.6 Register of Information (Art. 28.3)

- **Article:** Art. 28.3 + Commission Implementing Reg. (EU) 2024/2956
- **Format:** standardised templates (15 tables — entity, contracts, providers,
  ICT services, sub-providers, ICT-services classifications, etc.)
- **Submission:** at least **annually to NCA**, or **on request by NCA** (Art. 28.3)
- ESAs receive annually for systemic-risk monitoring
- This is **not a policy** — it is a structured register; the wizard should
  generate a **"Register Maintenance Procedure"** instead

---

## 6. Information Sharing Arrangements Policy (Art. 45)

- **Article:** Art. 45.1–45.3
- **Status:** voluntary; explicitly permitted by DORA
- **Required content:**
  - Approved trust circles (e.g. FS-ISAC, sectoral CERTs, national CERTs,
    Bundesbank Operational-Resilience-Forum for DE banks)
  - Information-classification before sharing (Traffic Light Protocol)
  - Reciprocity agreements
  - Personal-data-protection (GDPR coupling — Art. 45.2)
- **Tenant inputs:** trust-circle memberships; default TLP-level
- **ISO 27001 overlap:** EXTENDS **A.5.6** (Contact with special interest groups)
  + **A.5.7** (Threat intelligence)
- **Approval body:** CISO
- **Review cadence:** annually

---

## 7. Tenant-Settings-Inputs (consolidated wizard schema)

Group inputs into **6 wizard steps**:

### Step 1 — Entity Type & Sector

- Entity type (radio-list per DORA Art. 2 categories)
- NACE / sector code
- Group-level entity Y/N (drives Art. 5 group-application)
- Microenterprise Y/N → forks into simplified ICT-RMF (Art. 16)

### Step 2 — Significance & Scope

- Significance flag (Y/N — set by NCA, configurable in tenant)
- Last TLPT date (date picker, optional)
- Significant-function list (multi-select from existing business-process module)

### Step 3 — Critical/Important Function Mapping

- Auto-import from existing **BCM module** business-process inventory
- Tag each process with criticality (critical / important / other) per
  MiFID II / CRR / Solvency II definitions
- Map to supporting ICT assets (asset module link)
- Map to supporting ICT third-party providers (supplier module link)

### Step 4 — Existing ICT-third-party Register baseline

- Importer for current contract list (CSV / vendor-management tool)
- Fields populated for Register of Information (15 tables)
- Identification of top-N concentration providers

### Step 5 — Competent Authority & Reporting

- Competent authority (DE: BaFin / Bundesbank / BMF; AT: FMA;
  EU-direct: ESMA for CTPPs, EZB/SSM for SI-banks)
- Reporting channel (BaFin MVP, EBA platform, etc.)
- 24/7 SPOC contact (name + phone + e-mail)
- Default incident-classification thresholds (override RTS defaults Y/N)

### Step 6 — Concentration & Tolerance

- Concentration thresholds per provider (% of CIF count or revenue)
- RTO/RPO defaults per criticality tier
- Risk-tolerance qualitative bands (reputational, regulatory)
- Crypto-agility horizon (PQC migration target year)

---

## 8. Hierarchy Considerations

### 8.1 Group-level application

- DORA Art. 5: ICT-RMF approved by **management body** — applies at
  the **legal entity level** (each subsidiary), but DORA permits
  **group-level frameworks** that subsidiaries adopt (Art. 6.10
  — "Union parent undertaking" provision).
- **Konzern-CISO** (group CISO) governs the master ICT-RMF; subsidiaries
  inherit and may add local addenda.

### 8.2 Subsidiary inheritance pattern

- The wizard MUST support **inheritance from holding tenant**:
  - Subsidiary tenant inherits the holding's ICT-RMF as **read-only baseline**
  - Subsidiary may override **only with stricter requirements** (lex specialis principle)
  - Local-NCA addenda (e.g. BaFin MaRisk + BaIT — note: BaIT is being
    superseded by DORA per Jan 2025) layered on top
- Existing **ROLE_GROUP_CISO** + **ROLE_KONZERN_AUDITOR** roles map
  naturally to the inheritance approval workflow.

### 8.3 Cross-border subsidiaries

- DORA Art. 1.2: lex specialis — DORA prevails over national legislation
  on the same subject matter
- Within the EU: harmonised
- Outside EU (subsidiary in CH / UK / US): DORA does not apply directly,
  but the EU parent must ensure third-country provider chains comply
  via Art. 28 contract clauses
- Wizard MUST display jurisdiction of each subsidiary and only emit
  DORA mandates for EU-based entities

### 8.4 Critical ICT Third-Party Providers (CTPPs)

- ESAs designate CTPPs (Art. 31). CTPP receives a **Lead Overseer**
  (one of EBA/ESMA/EIOPA based on systemic-relevance ratio per RTS).
- A CTPP-tenant uses a **different policy set** (oversight-fee + RTS Art. 33
  recommendations + remediation plans). The wizard should include a
  "CTPP-mode" toggle that suppresses entity-side mandates and
  emits CTPP-side documents instead. **Out of scope for v1.**

---

## 9. Risks of Auto-Generated DORA Policies

### 9.1 Audit-rejection risk

- ESAs and NCAs (esp. **BaFin** under § 44 KWG audit + dedicated DORA
  inspection programme) explicitly reject template-only documents.
- Audit findings track record so far (Q1/2026): **80 %+ of inspected
  entities** received material findings on tailoring depth, evidence
  granularity, or testing coverage. Generated baselines are a *starting
  point*, not a deliverable.

### 9.2 Evidence weight beats document weight

- DORA emphasises evidence-of-execution over policy-existence:
  - **Resilience-testing actually executed** (test reports, not just plans)
  - **Register of Information actually maintained** (timestamped revisions)
  - **Incidents actually classified** (with documented rationale)
  - **TLPT actually performed** (NCA attestation)
  - **Backups actually restorable** (restoration-test logs)
- Wizard MUST instrument follow-up tasks linked to each generated policy:
  "Generate evidence within X days" — escalating to CISO if not closed.

### 9.3 Effective-date metadata

- DORA implementation: **17 January 2025**
- Generated policies MUST carry:
  - `validity_from = max(policy_creation_date, 2025-01-17)`
  - `regulatory_basis = "Regulation (EU) 2022/2554 — DORA"`
  - `tier_2_basis = list of cited RTS/ITS`
  - Version number, approval-date, next-review-date

### 9.4 Penalties

- Art. 50.3: administrative penalties up to **1 % of average daily
  worldwide turnover** of the preceding business year, **per day**, for
  ongoing breach (NCA-imposed, capped per national law).
- CTPPs (Art. 35.6 + Art. 36): periodic penalty payments up to **1 % of
  average daily worldwide turnover** of preceding year per day, for up to
  6 months.
- This is a strong incentive for evidentiary depth — generated policies
  must be defensible end-to-end.

### 9.5 Drift risk

- RTS still being finalised (subcontracting RTS rejected Jul 2024,
  re-submission expected H1/2026). The wizard MUST mark generated
  subcontracting clauses as "**provisional — RTS final adoption pending**"
  and prompt re-generation when final text published.

---

## 10. Cross-Mapping to ISO 27001

> Drives whether the wizard generates a **NEW** Document or
> **APPENDS** to an existing ISO-generated one.

| DORA mandate | Action | ISO 27001 anchor | Wizard behaviour |
|---|---|---|---|
| ICT-RMF Policy (Art. 6) | EXTENDS | Clause 4–6 + A.5.1 | Append DORA-section to ISMS-Manual |
| ICT Risk Tolerance Statement (Art. 6.8) | EXTENDS | Risk-Appetite-Statement | Append DORA section |
| ICT Asset Mgmt Policy (Art. 8) | EXTENDS | A.5.9, A.5.10 | Append section |
| Identification + Classification (Art. 8) | EXTENDS | A.5.12 | Append: classification of *functions* |
| ICT Operations Security (Art. 9.2) | EXTENDS | A.8.6, A.8.8, A.8.9, A.8.15, A.8.32 | Append |
| Network Security (Art. 9.4) | REPLACES | A.8.20–A.8.23 | New stricter doc, ISO version archived |
| Cryptography (Art. 9.4.b) | EXTENDS | A.8.24 | Append crypto-agility / PQC section |
| Physical + Environmental (Art. 9.4) | EXTENDS | A.7.1–A.7.14 | Append geographic-separation section |
| ICT Project Management (Art. 9.4.f) | EXTENDS | A.5.8 | Append |
| Acquisition / Development (Art. 9.4.g) | EXTENDS | A.8.25–A.8.31 | Append SDLC + EOL section |
| Detection of Anomalous Activities (Art. 10) | EXTENDS | A.8.16, A.5.7 | Append SOC + thresholds |
| ICT Response + Recovery (Art. 11) | EXTENDS | A.5.29, A.5.30 | Append annual-testing requirement |
| Backup (Art. 12) | EXTENDS | A.8.13 | Append physical/logical-segregation + restoration-evidence |
| Learning + Evolving (Art. 13) | EXTENDS | A.6.3, A.5.27 | Append management-body training |
| Communication on Incidents (Art. 14) | EXTENDS | A.5.5, A.5.6 | Append client-notification |
| Incident Mgmt + Reporting (Art. 17–23) | EXTENDS | A.5.24–A.5.28 | Append DORA-classification + 4h/72h/1m timelines |
| Resilience Testing Programme (Art. 24–25) | NEW | (none) | New standalone document |
| TLPT Programme (Art. 26–27) | NEW | (loose tie A.8.29) | New standalone document |
| ICT Third-Party Strategy (Art. 28) | EXTENDS | A.5.19–A.5.23 | Append concentration + due-diligence |
| DORA Contract Addendum (Art. 30) | NEW | (none — contract not policy) | Standalone clause-template |
| Concentration Risk Policy (Art. 29) | NEW | (none) | New standalone document |
| Exit Strategy Policy (Art. 28.8) | EXTENDS | A.5.22 | Append annual-exit-test |
| Subcontracting Governance (Art. 30.2.a) | NEW | (loose tie A.5.21) | New standalone document |
| Register of Information (Art. 28.3) | NEW | (none — register not policy) | Procedure + linked register |
| Information Sharing (Art. 45) | EXTENDS | A.5.6, A.5.7 | Append trust-circle list |

**Summary:**

- 6 NEW documents (Resilience Testing Programme, TLPT Programme, Concentration
  Risk Policy, Subcontracting Governance, DORA Contract Addendum, Register
  Maintenance Procedure)
- 18 EXTENSIONS to existing ISO documents
- 1 REPLACEMENT (Network Security — DORA stricter than A.8.20–23)

---

## 11. Recommendations

### 11.1 Wizard structure

1. **Gating toggle**: "Entity in DORA scope?" tenant-setting at top of
   Compliance-Wizard. If NO → DORA step skipped entirely. If YES →
   surface DORA-step after the ISO 27001 baseline step.

2. **Microenterprise fork**: if microenterprise = Y → present simplified
   ICT-RMF (Art. 16) — fewer policies, no TLPT, no testing-programme.

3. **CTPP-mode toggle**: present but disabled in v1; future scope.

4. **Inheritance-aware**: subsidiary tenant under a holding inherits the
   holding's DORA framework as read-only; only deltas locally editable.

### 11.2 Document tagging

- Each generated policy MUST carry a **DORA-status badge** with format:
  `"Policy fulfils DORA Art. <X.Y> + ISO A.<a.b>"`
- Badge renders in document header + in document-list view + in audit-export.
- Provides audit-trail evidence for inspectors.

### 11.3 Cross-module integration

- **ICT-Third-Party Register**: own module, NOT a policy — linked from
  Supplier module. Wizard adds a "Generate Register-Maintenance Procedure"
  step that wires the Register module to the procedure document.
- **Incident module**: wizard injects a "DORA classification" panel into
  existing Incident form, evaluates the 7 RTS criteria automatically, and
  triggers a `dora-incident-major` workflow analogous to `data-breach`.
  Cross-link to Data-Breach workflow if Art. 33 GDPR also fires.
- **BCM module**: feeds critical-function inventory directly into
  Identification + Classification policy generation.
- **Asset module**: feeds dependencies map into ICT Asset Mgmt policy.

### 11.4 Evidence loops

- For each generated policy, the wizard MUST schedule follow-up evidence
  tasks (scheduled-reports module):
  - "Restoration test due in 90 days" (Backup Policy)
  - "Annual ICT-resilience test due" (Response + Recovery Policy)
  - "TLPT due in <date based on +3 years>" (TLPT Programme)
  - "Register of Information annual submission due" (Register Procedure)
  - "Exit-plan test due" (Exit Strategy Policy)
- Tasks escalate to Konzern-CISO if not closed before regulatory deadline.

### 11.5 Re-generation triggers

- New RTS/ITS published → wizard prompts re-generation of affected policies
- Material change to entity scope (M&A, new product line) → re-run wizard
- Annual review cycle → calendar reminder to re-run wizard with current
  RTS catalogue

### 11.6 Export channels

- Generated policies must be exportable as:
  - PDF (signed via existing document-signing module)
  - DOCX (for client edit-rounds)
  - Structured XML/JSON (for NCA submissions where required, e.g.
    Register of Information per ITS (EU) 2024/2956)

### 11.7 What the wizard MUST NOT do

- Do **not** fabricate concentration thresholds — set defaults but force
  user confirmation
- Do **not** generate TLPT scope without NCA-significance confirmation
- Do **not** issue final policies without management-body approval
  workflow gate (Art. 5 — explicit non-delegable)
- Do **not** auto-classify functions as "critical" without
  Business-Continuity-Manager confirmation
- Do **not** copy ISO content verbatim into DORA documents — use
  cross-reference + delta only (saves effort and prevents drift)

---

## 12. Open questions for product team

1. **CTPP support in v1?** Currently scoped out — confirm.
2. **Microenterprise simplified RMF** — separate wizard or branch within main wizard?
3. **Subcontracting RTS** — generate provisional clauses or wait for final adoption?
4. **TIBER-EU integration** — direct Bundesbank API or PDF-only export?
5. **Register of Information** — own dedicated module recommended (likely
   too complex for policy-wizard scope alone).
6. **Effective-date back-fill** — for entities that should have been compliant
   since 2025-01-17, generate retro-active policies dated 2025-01-17 or
   current-date with a note?
7. **Multi-jurisdiction subsidiaries** — UK FCA Operational Resilience
   regime is similar but not identical; do we cover it as a sibling addon?
   (Recommend: separate UK-FCA addon, do not co-mingle with DORA.)

---

*End of input. Next document in series: `04-bsi-input.md` (BSI IT-Grundschutz
+ C5 deltas).*
