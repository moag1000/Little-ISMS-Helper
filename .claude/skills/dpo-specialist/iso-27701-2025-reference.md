# ISO 27701:2025 - Privacy Information Management System (PIMS)
## Quick Reference for DPO Specialist

### Standard Overview

**Full Title:** ISO/IEC 27701:2025 - Security techniques — Extension to ISO/IEC 27001 and ISO/IEC 27002 for privacy information management — Requirements and guidelines

**Purpose:** Extends ISO 27001:2022 ISMS with privacy-specific requirements for both **Controllers** and **Processors** (GDPR terminology)

**Latest Version:** ISO/IEC 27701:2025 (published 2025)
**Previous Version:** ISO/IEC 27701:2019 (first PIMS standard)

**Relationship to Standards:**
- **Extends** ISO/IEC 27001:2022 (ISMS requirements)
- **Extends** ISO/IEC 27002:2022 (Security controls)
- **Supports** GDPR compliance (EU 2016/679)
- **Aligns with** ISO 29100 (Privacy framework)
- **Compatible with** ISO 27018 (Cloud privacy), ISO 27552 (PII deletion), ISO 31700 (Privacy by Design)

---

## What's New in 2025 Edition

**Major Updates from 2019:**

1. **Enhanced Controller Requirements (Clause 6.2)**
   - Expanded guidance on consent management (GDPR Art. 7)
   - New controls for data subject rights automation (GDPR Art. 15-22)
   - Enhanced privacy notice management
   - Legitimate interest assessment (LIA) template

2. **Processor Requirements Refined (Clause 6.3)**
   - Clearer guidance on processor-controller agreements (GDPR Art. 28)
   - Enhanced sub-processor management
   - Data breach notification to controller timelines
   - Return/deletion of data procedures

3. **AI and Automated Decision-Making (NEW Clause 6.4)**
   - Controls for automated profiling (GDPR Art. 22)
   - AI transparency requirements
   - Algorithmic impact assessments
   - Bias detection and mitigation

4. **Cross-Border Transfers (Enhanced Clause 6.5)**
   - Updated for Schrems II implications
   - Transfer Impact Assessment (TIA) requirements
   - Supplementary measures guidance
   - Real-time monitoring of adequacy decisions

5. **Privacy by Design and Default (Expanded Clause 6.6)**
   - Privacy Engineering principles
   - Data minimization techniques
   - Pseudonymization and anonymization guidance
   - Privacy-preserving technologies (PETs)

6. **Children's Data (NEW Clause 6.7)**
   - Age verification mechanisms
   - Parental consent processes
   - Child-appropriate privacy notices
   - Special protections for minors

7. **Incident Response (Enhanced Clause 7)**
   - 72-hour breach notification workflows (GDPR Art. 33)
   - Data subject notification criteria (GDPR Art. 34)
   - Breach assessment templates
   - Lessons learned integration

8. **Privacy Metrics and Monitoring (NEW Clause 8)**
   - Privacy KPIs and KRIs
   - DPIA effectiveness measurement
   - Consent withdrawal rates
   - Data subject request response times

---

## Standard Structure

### Clause 1: Scope
Defines applicability to organizations processing PII

### Clause 2: Normative References
- ISO/IEC 27001:2022
- ISO/IEC 27002:2022
- ISO 29100:2011 (Privacy framework)

### Clause 3: Terms and Definitions
Key terms (see Definitions section below)

### Clause 4: General
Overview of PIMS concept

### Clause 5: PIMS-Specific Guidance (Extensions to ISO 27001)
- 5.2: Understanding organization and context (privacy considerations)
- 5.3: Leadership (privacy governance)
- 5.4: Planning (privacy objectives)
- 5.5: Support (privacy competence)
- 5.6: Operation (privacy risk assessment)
- 5.7: Performance evaluation (privacy monitoring)
- 5.8: Improvement (privacy continual improvement)

### Clause 6: PIMS-Specific Controls (Extensions to ISO 27002)
- **6.2: Controller Controls** (34 controls)
- **6.3: Processor Controls** (12 controls)
- **6.4: AI and Automated Decision-Making Controls** (8 controls - NEW!)
- **6.5: Cross-Border Transfer Controls** (6 controls - Enhanced)
- **6.6: Privacy by Design Controls** (10 controls - Expanded)
- **6.7: Children's Data Controls** (5 controls - NEW!)

### Clause 7: PIMS Incident Management (NEW)
Privacy-specific incident response

### Clause 8: PIMS Monitoring and Measurement (NEW)
Privacy metrics and KPIs

---

## Clause 5: PIMS-Specific Guidance (Extensions to ISO 27001)

### 5.2 Understanding Organization & Context (Privacy)

**Extension to ISO 27001 Clause 4.1:**

**Privacy-Specific External Context:**
- **Regulatory:** GDPR, national data protection laws, sector-specific (HIPAA, CCPA, etc.)
- **Judicial:** Case law (Schrems I/II, Privacy Shield invalidation, Max Schrems rulings)
- **Technological:** Emerging technologies (AI, facial recognition, biometrics), privacy-enhancing technologies (PETs)
- **Societal:** Privacy expectations, cultural differences (EU vs. US privacy culture)

**Privacy-Specific Internal Context:**
- **Business Model:** How PII is used for value creation (advertising, profiling, analytics)
- **Data Flows:** End-to-end mapping of PII processing
- **Legacy Systems:** Technical debt affecting privacy controls
- **Organizational Culture:** Privacy awareness, "privacy by default" mindset

**Example:**
```
E-commerce Company Privacy Context:

External:
- GDPR applies (EU customers)
- ePrivacy Directive (cookies, tracking)
- Consumer Protection laws
- Schrems II implications (US cloud providers)
- Rising consumer privacy awareness

Internal:
- Business model: Personalized recommendations (profiling)
- PII: 500,000 customers (names, addresses, purchase history, browsing data)
- Data flows: Website → CRM → Analytics → Marketing → Third-party ads
- Legacy: 10-year-old customer database (no privacy by design)
- Culture: Sales-driven (privacy often secondary concern)

PIMS Implications:
- DPIA required for profiling (GDPR Art. 35)
- Consent management for cookies (ePrivacy)
- Data minimization (legacy data cleanup)
- Privacy culture transformation program
```

---

### 5.3 Leadership (Privacy Governance)

**Extension to ISO 27001 Clause 5:**

**Top Management Commitments (Privacy-Specific):**

1. **Privacy Policy** - Board-approved privacy statement
   - Aligned with business strategy
   - Publicly available
   - Reviewed annually

2. **Data Protection Officer (DPO)** - GDPR Art. 37-39 or equivalent
   - Independent position
   - Reports to highest management level
   - Adequate resources

3. **Privacy Governance Structure**
   - Privacy Committee (quarterly meetings)
   - Privacy Champions network (departmental representatives)
   - Cross-functional privacy working groups

4. **Privacy Budget**
   - Privacy enhancing technologies (PETs)
   - DPIA resources
   - Privacy training programs
   - Privacy audits

**Example Privacy Governance:**
```
Privacy Governance Structure:

Board of Directors
├─ Privacy Committee (Quarterly)
│  ├─ CEO (Chair)
│  ├─ DPO (Secretary)
│  ├─ CTO
│  ├─ Chief Legal Officer
│  └─ Chief Marketing Officer
│
├─ Data Protection Officer (DPO)
│  ├─ Privacy Team (3 FTE)
│  ├─ Privacy Champions Network (12 departments)
│  └─ External Privacy Counsel
│
└─ Privacy Working Groups
   ├─ Cookie Consent Working Group
   ├─ Data Subject Rights Working Group
   └─ AI Ethics Working Group

Budget 2025: €500,000
- PETs: €200,000 (pseudonymization, encryption)
- DPIAs: €100,000 (external consultants)
- Training: €100,000 (all employees + specialized)
- Tools: €100,000 (consent management platform, DSAR automation)
```

---

### 5.6 Operation (Privacy Risk Assessment)

**Extension to ISO 27001 Clause 8:**

**Privacy-Specific Risk Assessment (DPIA - GDPR Art. 35):**

ISO 27701:2025 provides detailed **Data Protection Impact Assessment (DPIA)** methodology:

**When DPIA Required (GDPR Art. 35(3) + ISO 27701 Guidance):**

1. **Systematic Monitoring** - Large-scale tracking (CCTV, online behavioral tracking, location data)
2. **Special Categories (Art. 9)** - Health, biometric, genetic, race, religion, political opinions, sexual orientation
3. **Large-Scale Processing** - >5,000 data subjects (2025 clarification), or organization-wide processing
4. **Automated Decision-Making (Art. 22)** - Profiling with legal/significant effects (credit scoring, recruitment algorithms)
5. **Innovative Technology** - First use of new technology (facial recognition, emotion AI)
6. **Data Matching/Combining** - Combining datasets from multiple sources
7. **Vulnerable Subjects** - Children, employees, refugees, elderly
8. **Public Access Areas** - Processing in publicly accessible areas (video surveillance)
9. **Preventing Data Subject Rights** - Processing that prevents exercise of rights
10. **Cross-Border Transfers** - Transfers to third countries without adequacy decision

**DPIA Process (ISO 27701:2025 Template):**

**Phase 1: Necessity and Proportionality**
- Is processing necessary for specified purpose?
- Can purpose be achieved with less privacy-intrusive means?
- Proportionality test (benefits vs. privacy risks)

**Phase 2: Risk Identification**
- Threats to PII (unauthorized access, loss, alteration, disclosure)
- Likelihood assessment (1-5 scale)
- Impact on data subjects (1-5 scale: negligible to severe)

**Phase 3: Risk Mitigation**
- Technical measures (encryption, pseudonymization, access control)
- Organizational measures (policies, training, audits)
- Contractual measures (processor agreements, joint controller arrangements)

**Phase 4: Residual Risk Assessment**
- Risk after mitigation
- Acceptable risk level (low, medium) or escalation (high, critical → Art. 36 consultation)

**Phase 5: DPO Consultation** (GDPR Art. 35(4))
- DPO review and advice
- Documented recommendation

**Phase 6: Data Subject Consultation** (GDPR Art. 35(9) - where appropriate)
- Seek views of data subjects or their representatives
- Document feedback and how addressed

**Phase 7: Approval and Review**
- Management approval
- Review triggers (annually, or upon significant change)

**Example DPIA (Simplified):**
```
DPIA: Employee Wellness App with Health Tracking

1. Necessity & Proportionality:
   ✅ Necessary: Workplace health promotion program
   ✅ Less intrusive means? No - voluntary participation, individual choice
   ✅ Proportional: Health benefits (stress reduction, fitness) vs. Privacy risks

2. Risk Identification:
   Risks:
   a) Unauthorized access to health data
      - Likelihood: Possible (3/5) - app vulnerabilities
      - Impact: Severe (5/5) - sensitive health data (Art. 9)
      - Initial Risk: 15/25 (High)

   b) Indirect discrimination (non-participants stigmatized)
      - Likelihood: Likely (4/5) - human behavior
      - Impact: Moderate (3/5) - workplace atmosphere
      - Initial Risk: 12/25 (Medium)

3. Risk Mitigation:
   a) Unauthorized access:
      - Encryption (AES-256 at rest, TLS 1.3 in transit)
      - Pseudonymization (employee ID, not name)
      - Access control (ISO 27001 A.5.15)
      - Penetration testing (annual)
      → Residual Likelihood: Rare (1/5)
      → Residual Risk: 5/25 (Low) ✓

   b) Discrimination:
      - Works Council agreement (no disadvantage for non-participants)
      - Awareness campaign (voluntary participation)
      - HR policy (explicitly prohibits stigmatization)
      → Residual Likelihood: Unlikely (2/5)
      → Residual Risk: 6/25 (Low) ✓

4. Residual Risk: LOW (acceptable)

5. DPO Consultation:
   "Measures are adequate. Recommend:
   - Explicit consent (Art. 9(2)(a))
   - Right to withdraw anytime
   - No employer access to individual health data"

6. Data Subject Consultation:
   - Works Council consulted (representative)
   - Employee survey: 78% positive, concerns about data security (addressed by encryption)

7. Approval: CISO (2025-01-15)
   Review: 2026-01-15 or upon significant change
```

---

## Clause 6.2: Controller Controls (34 Controls)

**ISO 27701:2025 Controller Controls** address **GDPR Articles 5-34**

### Control Group 1: Lawfulness and Consent (Controls 6.2.1 - 6.2.4)

**6.2.1 - Identify and Document Legal Basis (GDPR Art. 6)**
- Control: Before processing PII, identify and document legal basis (Art. 6(1)(a-f))
- Implementation: ProcessingActivity.legalBasis field
- Evidence: Records of Processing Activities (VVT) with legal basis per purpose

**6.2.2 - Obtain and Record Consent (GDPR Art. 7 - Enhanced in 2025)**
- Control: Obtain freely given, specific, informed, unambiguous consent
- Requirements (2025):
  - **Granular consent** - Separate consent per purpose
  - **Pre-ticked boxes prohibited** - Affirmative action required
  - **Withdrawal as easy as giving** - One-click withdrawal
  - **Proof of consent** - Who, when, what, how stored for 7 years
  - **Re-consent** - If purpose changes significantly
- Implementation: Consent entity (recommended), consent management platform
- Evidence: Consent records with timestamp, IP, consent text version, withdrawal date

**6.2.3 - Facilitate Consent Withdrawal (GDPR Art. 7(3) - NEW 2025 Requirement)**
- Control: Enable easy withdrawal of consent
- Requirements:
  - Withdrawal method as accessible as consent method
  - Effect within 24 hours (2025 guidance)
  - No disadvantage for withdrawal (e.g., continue non-consent-based services)
  - Audit trail of withdrawals
- Implementation: Consent.withdrawalDate field, automated workflows

**6.2.4 - Legitimate Interests Assessment (GDPR Art. 6(1)(f) - Enhanced 2025)**
- Control: Perform and document Legitimate Interest Assessment (LIA) when using Art. 6(1)(f)
- LIA Template (ISO 27701:2025):
  1. **Purpose Test** - Is there a legitimate interest?
  2. **Necessity Test** - Is processing necessary for that interest?
  3. **Balancing Test** - Do data subject interests/rights override?
  4. **Safeguards** - What measures protect data subjects?
  5. **Conclusion** - Document decision
- Example: Direct marketing to existing customers (case law: ePrivacy allows if soft opt-in)

---

### Control Group 2: Data Minimization and Purpose Limitation (Controls 6.2.5 - 6.2.7)

**6.2.5 - Limit Collection to Identified Purposes (GDPR Art. 5(1)(b,c))**
- Control: Collect only PII necessary for specified, explicit, legitimate purposes
- Implementation:
  - Data mapping (what PII collected, why needed?)
  - Privacy by design (forms collect minimal data)
  - Regular review (is this field still necessary?)

**6.2.6 - Accuracy and Up-to-Date (GDPR Art. 5(1)(d))**
- Control: Ensure PII is accurate and kept up to date
- Implementation:
  - Regular data quality checks
  - Self-service update portals (customers update own data)
  - Inaccuracy flagging mechanism
  - Erasure of inaccurate data

**6.2.7 - Retention Limits (GDPR Art. 5(1)(e))**
- Control: Retain PII no longer than necessary
- Implementation:
  - Retention schedules per ProcessingActivity
  - Automated deletion workflows
  - Legal hold processes (litigation, investigations)
  - Anonymization after retention period (if statistical value)

---

### Control Group 3: Data Subject Rights (Controls 6.2.8 - 6.2.15 - Expanded 2025)

**6.2.8 - Access Request Procedure (GDPR Art. 15 - Automated in 2025)**
- Control: Enable data subjects to obtain copy of their PII within 1 month
- Requirements (2025):
  - **Automated self-service portal** (preferred)
  - **Identity verification** (prevent impersonation)
  - **Structured format** (JSON, XML, CSV)
  - **Third-party recipients** disclosure
  - **Free of charge** (first request)
- Implementation: DataSubjectRequest entity, self-service portal, automated export

**6.2.9 - Rectification Procedure (GDPR Art. 16)**
- Control: Enable data subjects to correct inaccurate PII
- Requirements:
  - Self-service update where feasible
  - Verification of corrections
  - Notify third-party recipients (Art. 19)

**6.2.10 - Erasure Procedure (GDPR Art. 17 "Right to be Forgotten")**
- Control: Delete PII when criteria met
- Criteria:
  - No longer necessary for purpose
  - Consent withdrawn (no other legal basis)
  - Objection to processing (Art. 21)
  - Unlawful processing
  - Legal obligation to erase
- Exceptions (retain):
  - Legal obligation (e.g., tax records)
  - Legal claims (litigation hold)
  - Public interest
- Implementation:
  - Erasure workflow with legal basis check
  - Hard delete vs. soft delete (audit requirements)
  - Third-party notification (Art. 19)

**6.2.11 - Restriction Procedure (GDPR Art. 18)**
- Control: Restrict processing when contested
- Scenarios:
  - Accuracy disputed (restriction until verified)
  - Unlawful but data subject opposes erasure
  - No longer needed but data subject needs for legal claim
  - Objection pending (Art. 21)
- Implementation: ProcessingActivity.status = "restricted", access control block

**6.2.12 - Data Portability (GDPR Art. 20 - Enhanced 2025)**
- Control: Provide PII in structured, commonly used, machine-readable format
- Requirements (2025):
  - **JSON** or **XML** preferred (CSV acceptable)
  - **Metadata** included (categories, purposes, retention)
  - **Direct transmission** to another controller (if technically feasible)
- Scope: Only consent-based or contract-based processing (not Art. 6(1)(c-f))
- Implementation: Automated export API

**6.2.13 - Objection Procedure (GDPR Art. 21)**
- Control: Enable objection to processing
- Types:
  - **Art. 6(1)(e,f)** - Objection for particular situation → Controller must cease unless compelling legitimate grounds
  - **Direct marketing** - Absolute right to object → Controller must cease immediately
- Implementation: Objection workflow, marketing suppression list

**6.2.14 - Automated Decision-Making Opt-Out (GDPR Art. 22 - NEW 2025 Requirement)**
- Control: Inform about automated decision-making, enable human review request
- Requirements (2025):
  - **Transparency** - Explain logic, significance, consequences
  - **Human intervention** - Right to request human review
  - **Challenge** - Right to contest decision
  - **Explainability** - AI model interpretability (NEW 2025 - see Clause 6.4)

**6.2.15 - Deadline Compliance (GDPR Art. 12(3) - Monitored 2025)**
- Control: Respond to data subject requests within 1 month (extendable to 3 months for complex requests)
- KPI (NEW 2025): Track average response time, breaches
- Implementation: DataSubjectRequest.receivedAt, deadlineAt, completedAt

---

### Control Group 4: Third Parties and Processors (Controls 6.2.16 - 6.2.20)

**6.2.16 - Processor Agreements (GDPR Art. 28 - Enhanced 2025)**
- Control: Written contracts with processors containing Art. 28(3) mandatory clauses
- Mandatory Clauses (2025 Checklist):
  1. **Subject matter and duration** of processing
  2. **Nature and purpose** of processing
  3. **Type of PII** and categories of data subjects
  4. **Controller obligations and rights**
  5. **Processor obligations**:
     - Process only on documented instructions
     - Ensure confidentiality of personnel
     - Implement appropriate security measures (Art. 32)
     - Engage sub-processors only with written authorization
     - Assist with data subject rights (Art. 15-22)
     - Assist with DPIA (Art. 35) and consultation (Art. 36)
     - Delete/return PII after end of services
     - Make available all information for audits
  6. **Sub-processor requirements** (see 6.2.17)
  7. **Data breach notification** (72-hour upstream notification to controller)
  8. **Audit rights** (controller or third-party auditor)
  9. **Liability and indemnification**
  10. **Data location** and cross-border transfers
- Implementation: ProcessingActivity.processors array with contractDate

**6.2.17 - Sub-Processor Management (GDPR Art. 28(2,4) - NEW 2025 Detail)**
- Control: Processors may engage sub-processors only with prior written authorization
- Requirements (2025):
  - **General authorization** (list of sub-processors) OR **Specific authorization** (case-by-case)
  - **Objection period** (30 days minimum for general authorization)
  - **Same obligations** - Sub-processor bound by same data protection obligations
  - **Liability** - Processor remains fully liable to controller
  - **Sub-processor register** - Maintained and updated
- Implementation: Processor entity (recommended), sub-processor register

**6.2.18 - Joint Controller Arrangements (GDPR Art. 26)**
- Control: Transparent determination of responsibilities when joint controllers
- Requirements:
  - **Written arrangement** - Who does what (purposes, means, data subject rights, security)
  - **Essence made available** to data subjects
  - **Single point of contact** - Data subjects can exercise rights against any controller
- Example: Website uses Facebook Pixel → Website + Facebook are joint controllers for visitor data

**6.2.19 - Third-Party Recipients (GDPR Art. 13(1)(e), 14(1)(e))**
- Control: Identify and inform data subjects about recipients
- Categories: Payment processors, delivery companies, marketing platforms, analytics providers
- Implementation: ProcessingActivity.recipientCategories, recipientDetails

---

### Control Group 5: Transparency and Communication (Controls 6.2.20 - 6.2.24)

**6.2.20 - Privacy Notice (GDPR Art. 13, 14 - Enhanced 2025)**
- Control: Provide concise, transparent, intelligible privacy information
- Requirements (Art. 13 - Data Collected Directly):
  1. **Identity and contact details** of controller and DPO
  2. **Purposes** of processing and legal basis
  3. **Legitimate interests** (if Art. 6(1)(f))
  4. **Recipients** or categories of recipients
  5. **Third country transfers** and safeguards
  6. **Retention period** or criteria
  7. **Data subject rights** (access, rectification, erasure, restriction, portability, objection)
  8. **Right to withdraw consent** (if applicable)
  9. **Right to lodge complaint** with supervisory authority
  10. **Automated decision-making** (if applicable) - logic, significance, consequences
  11. **Source** of data (if not collected from data subject - Art. 14)
- Format (2025 Guidance):
  - **Layered approach** - Short notice + full policy
  - **Icons** - Standardized symbols (ongoing standardization)
  - **Plain language** - No legalese (readable by average person)
  - **Accessible** - WCAG 2.1 AA compliance
- Implementation: PrivacyNotice entity (recommended), version control

**6.2.21 - Cookie/Tracking Consent (ePrivacy Directive + 2025 Guidance)**
- Control: Obtain consent before placing non-essential cookies
- Requirements (2025 - anticipating ePrivacy Regulation):
  - **Essential cookies exempt** (strictly necessary for service requested)
  - **Consent before placement** - No pre-ticked boxes
  - **Granular choice** - Per purpose (analytics, marketing, personalization)
  - **Refuse all** button - Same prominence as "Accept all"
  - **No cookie walls** - Service not conditional on non-essential cookies (unless consent is legal basis for service itself)
  - **Cookie lifespan** - Clear indication (session, 1 year, etc.)
- Implementation: Consent Management Platform (CMP), cookie banner

**6.2.22 - Communication Language and Accessibility (NEW 2025)**
- Control: Privacy information in data subject's language and accessible format
- Requirements:
  - **Multiple languages** - EU: Official language of member state where data subjects located
  - **Accessible formats** - Large print, screen reader compatible, audio, video sign language for disabled
  - **Children** - Age-appropriate language and format (see Clause 6.7)

---

## Clause 6.3: Processor Controls (12 Controls)

**ISO 27701:2025 Processor Controls** address **GDPR Art. 28-32** (Processor obligations)

### Key Processor Controls

**6.3.1 - Processing Instructions (GDPR Art. 28(3)(a))**
- Control: Process PII only on documented instructions from controller
- Implementation:
  - Written processing instructions (part of processor agreement)
  - Escalation process (if instruction appears unlawful → inform controller)
  - Audit trail (log of instructions received and executed)

**6.3.2 - Confidentiality (GDPR Art. 28(3)(b))**
- Control: Ensure persons authorized to process PII are under confidentiality obligation
- Implementation:
  - NDAs for employees, contractors
  - Confidentiality clauses in employment contracts
  - Training on confidentiality obligations

**6.3.3 - Security Measures (GDPR Art. 32)**
- Control: Implement appropriate technical and organizational measures
- See ISO 27001 Annex A controls (extended by ISO 27701)

**6.3.4 - Sub-Processor Engagement (GDPR Art. 28(2,4))**
- Control: Engage sub-processors only with prior written authorization
- Implementation: Sub-processor register, approval workflow

**6.3.5 - Assist with Data Subject Rights (GDPR Art. 28(3)(e))**
- Control: Assist controller in responding to data subject requests
- Implementation:
  - API for data export (Art. 15 access)
  - Deletion capabilities (Art. 17 erasure)
  - Update mechanisms (Art. 16 rectification)
  - SLA for assistance (e.g., respond within 5 business days to enable controller 1-month deadline)

**6.3.6 - Assist with DPIA (GDPR Art. 28(3)(f))**
- Control: Assist controller in ensuring compliance with Art. 35 (DPIA) and Art. 36 (prior consultation)
- Implementation: Provide information on data processing, security measures, sub-processors

**6.3.7 - Return/Delete PII (GDPR Art. 28(3)(g) - Enhanced 2025)**
- Control: Delete or return PII after end of services
- Requirements (2025):
  - **Timeline** - Within 30 days of contract end (unless legal obligation to retain)
  - **Method** - Secure deletion (overwriting, degaussing, physical destruction for hardware)
  - **Verification** - Certificate of deletion
  - **Copies** - Delete all copies including backups (except legally required)
- Implementation: Data deletion procedures, retention exceptions documented

**6.3.8 - Demonstrate Compliance (GDPR Art. 28(3)(h))**
- Control: Make available to controller all information necessary to demonstrate compliance
- Implementation:
  - SOC 2 Type II reports
  - ISO 27001 certificate
  - Audit logs
  - Processor transparency reports

**6.3.9 - Audits and Inspections (GDPR Art. 28(3)(h))**
- Control: Allow audits by controller or third-party auditor
- Requirements (2025):
  - **Frequency** - Annual or upon controller request
  - **Scope** - Mutually agreed (processor can limit for security/confidentiality)
  - **Timing** - Reasonable notice period (e.g., 30 days)
  - **Costs** - Usually controller bears audit costs
- Implementation: Audit clause in processor agreement

**6.3.10 - Data Breach Notification to Controller (GDPR Art. 33(2) - Enhanced 2025)**
- Control: Notify controller without undue delay upon becoming aware of personal data breach
- Timeline (2025 Guidance): **72 hours upstream** - Processor notifies controller within 72h, enabling controller to notify authority within 72h of becoming aware
- Content: Same as Art. 33(3) - nature, affected data subjects, likely consequences, measures
- Implementation: Incident response plan with controller notification workflow

**6.3.11 - Processor Records of Processing (GDPR Art. 30(2))**
- Control: Maintain records of all categories of processing carried out on behalf of controllers
- Content (simpler than controller records Art. 30(1)):
  - Name and contact details of processor, controllers, DPO
  - Categories of processing
  - Third country transfers and safeguards
  - TOMs (Art. 32)
- Implementation: Processor processing register

**6.3.12 - Respect Data Subject Rights (GDPR Art. 28(3)(e) - Clarified 2025)**
- Control: Do not process data subject requests directly (unless instructed by controller)
- Workflow:
  1. Processor receives data subject request → Forward to controller within 48h
  2. Controller instructs processor on action (fulfill, reject, etc.)
  3. Processor executes instruction → Confirm to controller
- Exception: Processor can provide own privacy notice if processor is also controller for some processing (e.g., HR data of processor's own employees)

---

## Clause 6.4: AI and Automated Decision-Making Controls (NEW 2025)

**ISO 27701:2025 introduces NEW controls** for **AI, profiling, and automated decision-making** (GDPR Art. 22)

### 6.4.1 - Transparency in Automated Decision-Making (GDPR Art. 13(2)(f), 14(2)(g))

**Control:** Inform data subjects about automated decision-making, including profiling

**Requirements:**
- **Meaningful information** about logic involved
- **Significance** - What does it mean for data subject?
- **Consequences** - What happens as result of decision?
- **Right to object** (Art. 22(3))

**Example:**
```
Automated Decision: Credit Scoring

Transparency Notice:
"We use automated decision-making to assess your creditworthiness.

Logic: Our algorithm analyzes your payment history (60% weight), income stability (30%), and credit utilization (10%) to calculate a credit score (0-100).

Significance: A score below 50 results in automatic loan rejection. Scores 50-70 may receive loan with higher interest rate. Scores 70+ receive standard rate.

Consequences: You may be denied credit or receive less favorable terms based solely on this automated assessment.

Your Rights:
- Request human review (Art. 22(3))
- Obtain explanation of decision
- Contest decision
- Request manual underwriting assessment (may take 10 business days)"
```

---

### 6.4.2 - Human-in-the-Loop for Significant Decisions (GDPR Art. 22(3) - Enhanced 2025)

**Control:** Do not base decisions solely on automated processing if legal/similarly significant effects, UNLESS exception applies

**Exceptions (GDPR Art. 22(2)):**
1. **Necessary for contract** (e.g., automated credit scoring for online loan)
2. **Authorized by EU/Member State law** with safeguards
3. **Based on explicit consent** (Art. 9(2)(a))

**Safeguards (Art. 22(3) - 2025 Detail):**
- **Right to obtain human intervention**
- **Right to express point of view**
- **Right to contest decision**
- **Qualified human reviewer** (competent, trained, different from original decision-maker)

**Implementation:**
- Automated decision flag: ProcessingActivity.hasAutomatedDecisionMaking
- Human review workflow: Request human review → Assign to qualified reviewer → Review → Final decision (may override algorithm)

---

### 6.4.3 - AI Explainability (NEW 2025 Requirement)

**Control:** Provide meaningful explanations of AI decisions

**Requirements (2025):**
- **Model-agnostic explanations** - Works for any model (LIME, SHAP)
- **Counterfactual explanations** - "If you had earned €5,000 more annually, you would have been approved"
- **Feature importance** - "Payment history was most influential factor (60%)"
- **Confidence scores** - "Algorithm is 92% confident in this decision"

**Example:**
```
Credit Denial Explanation:

Decision: Loan application DENIED

Reason: Credit score 42/100 (below threshold 50)

Contributing Factors:
1. Payment history (60% influence): 3 late payments in past 12 months → Score -20
2. Income stability (30% influence): 2 job changes in 24 months → Score -10
3. Credit utilization (10% influence): 85% of available credit used → Score -8

Counterfactual:
"If you had 0 late payments and credit utilization below 30%, your score would have been 70/100 (APPROVED)."

Actions to Improve Score:
- Avoid late payments for 12 months → +20 points
- Reduce credit utilization to < 30% → +8 points
- Maintain steady employment for 24 months → +10 points

Re-apply in 6 months for re-assessment.
```

---

### 6.4.4 - Algorithmic Bias Detection (NEW 2025)

**Control:** Test AI models for bias against protected characteristics

**Protected Characteristics (EU):**
- Race, ethnic origin
- Gender, gender identity
- Religion, beliefs
- Disability
- Age
- Sexual orientation

**Bias Testing (2025 Methods):**
- **Disparate impact analysis** - Compare approval rates across demographic groups
- **Confusion matrix by group** - Check if false negative/positive rates vary by protected characteristic
- **Fairness metrics** - Equalized odds, demographic parity, equal opportunity
- **Adversarial debiasing** - Train model to be invariant to protected attributes

**Example:**
```
AI Hiring Tool - Bias Audit Results:

Protected Characteristic: Gender

Acceptance Rates:
- Male candidates: 35% accepted
- Female candidates: 28% accepted
→ Disparate impact ratio: 28/35 = 0.80 (BELOW 0.80 threshold - BIAS DETECTED)

Root Cause Analysis:
- Feature "Years of continuous employment" correlates with gender (career breaks for maternity)
- Model learned to penalize career gaps disproportionately affecting women

Mitigation:
1. Remove "continuous employment" feature
2. Replace with "total years of experience" (gender-neutral)
3. Re-train model
4. Re-test: Acceptance rates: Male 34%, Female 33% → Ratio 0.97 ✓

Approval: Bias mitigation plan approved, model updated 2025-02-01
Review: Quarterly bias audits
```

---

### 6.4.5 - AI Model Governance (NEW 2025)

**Control:** Establish AI lifecycle governance

**AI Lifecycle Stages:**
1. **Design** - Privacy by design (Art. 25), DPIA (Art. 35)
2. **Development** - Training data quality, bias testing
3. **Deployment** - Human oversight, explanation interfaces
4. **Monitoring** - Performance metrics, bias drift detection
5. **Retirement** - Model sunset procedures, data deletion

**Example AI Governance:**
```
AI Model: Customer Churn Prediction

1. Design (2024-Q3):
   - DPIA completed (medium risk)
   - Legal basis: Legitimate interest (customer retention)
   - Data minimization: Use only behavioral data (not demographics)

2. Development (2024-Q4):
   - Training data: 3 years customer activity (anonymized)
   - Bias testing: No disparate impact by protected characteristics
   - Accuracy: 78% (acceptable)

3. Deployment (2025-Q1):
   - Human review: Marketing team reviews high-risk churn predictions
   - Explanation: Provide customers with personalized retention offers (not revealing prediction)
   - Transparency: Privacy notice updated with automated profiling info

4. Monitoring (Ongoing):
   - Monthly accuracy tracking
   - Quarterly bias audits
   - Semi-annual DPIA review (context change trigger)

5. Retirement (Planned 2027):
   - Model replaced every 2 years (concept drift)
   - Training data deleted after 6 months post-retirement
   - Explanation logs retained per retention schedule (2 years)
```

---

## Clause 6.5: Cross-Border Transfer Controls (Enhanced 2025)

**Enhanced for Schrems II implications and Transfer Impact Assessments (TIA)**

### 6.5.1 - Adequacy Decision (GDPR Art. 45 - Updated 2025)

**Control:** Verify current adequacy decisions before transferring to third countries

**Current Adequacy Decisions (as of 2025):**
- **Europe:** Andorra, Faroe Islands, Guernsey, Isle of Man, Jersey, Switzerland, UK
- **Asia-Pacific:** Japan, New Zealand, South Korea
- **Americas:** Argentina, Canada (commercial organizations), Uruguay
- **Special:** EU-US Data Privacy Framework (DPF) - replaced Privacy Shield in 2023

**Requirements:**
- **Monitor** - Adequacy decisions can be invalidated (e.g., Schrems I invalidated Safe Harbor, Schrems II invalidated Privacy Shield)
- **Fallback** - If adequacy withdrawn, immediately implement alternative safeguards (SCCs, BCRs)

**Implementation:** ProcessingActivity.transferSafeguards = "adequacy_decision", monitor EC adequacy decision list

---

### 6.5.2 - Standard Contractual Clauses (SCCs) (GDPR Art. 46(2)(c) - 2025 SCCs)

**Control:** Use EU-approved Standard Contractual Clauses for third country transfers

**Current SCCs (2021/2025 Versions):**
- **Module 1:** Controller to Controller
- **Module 2:** Controller to Processor
- **Module 3:** Processor to Processor
- **Module 4:** Processor to Controller

**Requirements:**
- **Select appropriate module** - Based on role (controller/processor)
- **Complete annexes** - Annex I (parties, data subjects, categories, purposes, retention), Annex II (TOMs), Annex III (sub-processors)
- **Supplementary measures** - Post-Schrems II: Assess need for additional safeguards (see 6.5.5 TIA)

**Implementation:** ProcessingActivity.transferSafeguards = "standard_contractual_clauses", transferSafeguardDetails (SCC execution date, module, version)

---

### 6.5.3 - Binding Corporate Rules (BCRs) (GDPR Art. 46(2)(b))

**Control:** Implement Binding Corporate Rules for intra-group transfers

**BCR Approval Process:**
1. Draft BCRs (legally binding internal code)
2. Submit to lead supervisory authority
3. Consistency mechanism (cooperation with other SAs)
4. Approval (can take 12-24 months)

**BCR Requirements:**
- Legally binding on all group entities
- Enforceable rights for data subjects
- Data protection principles (Art. 5)
- Data subject rights (Art. 15-22)
- Liability provisions

**Use Case:** Multinational corporations with frequent intra-group transfers (more efficient than SCCs for every transfer)

---

### 6.5.4 - Derogations (GDPR Art. 49 - Last Resort 2025 Guidance)

**Control:** Use derogations ONLY when no other safeguard available and transfer is occasional and necessary

**Derogations (Art. 49(1)):**
1. **Explicit consent** - Data subject explicitly consented to proposed transfer (fully informed of risks)
2. **Contract performance** - Necessary for contract with data subject
3. **Legal claims** - Necessary for establishment, exercise, defense of legal claims
4. **Vital interests** - Necessary to protect vital interests (life/death)
5. **Public register** - Transfer from public register (intended for public consultation)
6. **Compelling legitimate interest** - Controller's compelling legitimate interest (very restrictive, used sparingly)

**2025 Guidance:** Derogations are **NOT** a long-term solution. For ongoing/repeated transfers, use SCCs or BCRs.

---

### 6.5.5 - Transfer Impact Assessment (TIA) (Post-Schrems II - Mandatory 2025)

**Control:** Assess risks of third country transfers and implement supplementary measures if needed

**TIA Process (EDPB Recommendations 01/2020):**

**Step 1: Know Your Transfers**
- Map all transfers to third countries
- Identify legal basis (adequacy, SCCs, BCRs, derogation)

**Step 2: Verify Transfer Tool**
- SCCs still valid?
- Adequacy decision still in force?

**Step 3: Assess Third Country**
- **Legal system:** Does third country have surveillance laws conflicting with GDPR? (e.g., FISA 702, CLOUD Act in US)
- **Practice:** Is there evidence of government access to data? (e.g., NSA PRISM disclosures)
- **Legal remedies:** Can data subjects challenge government access?

**Step 4: Identify Supplementary Measures**
If third country laws undermine SCCs (e.g., government can compel access), implement supplementary measures:
- **Technical:** Encryption (data unreadable to third country government), pseudonymization, multi-party computation
- **Organizational:** Contractual obligations, transparency (notify if government request), legal challenges
- **Contractual:** Enhanced SCC clauses (transparency, challenge obligations)

**Step 5: Document and Re-Evaluate**
- Document TIA (like DPIA)
- Review periodically (legal changes, adequacy decisions)

**Example TIA:**
```
TIA: Customer Data Transfer to US Cloud Provider

Transfer Details:
- Data: Customer names, emails, IP addresses (not sensitive)
- Volume: 50,000 data subjects
- Recipient: Amazon Web Services (US) - Processor
- Safeguard: SCCs (Module 2 - Controller to Processor)

Third Country Assessment (USA):
- Legal Framework: FISA 702 allows surveillance of non-US persons, CLOUD Act enables government data access
- Practice: AWS has received government data requests (Transparency Report 2024: 350 requests, 60% complied)
- Legal Remedies: Limited for non-US persons (no standing in FISA court)

Risk Analysis:
- Likelihood of government access: LOW (customer data not security/intelligence interest)
- Impact if accessed: MODERATE (reputational, privacy)

Supplementary Measures:
1. **Encryption:** AES-256 encryption at rest, TLS 1.3 in transit (keys held in EU)
2. **Pseudonymization:** Customer names replaced with pseudonyms where feasible
3. **Data minimization:** Only transfer necessary data (not full customer profiles)
4. **EU data center:** AWS eu-central-1 (Frankfurt) with data residency commitment
5. **Contractual:** Enhanced SCC - AWS commits to notify us of government requests, challenge overbroad requests

Residual Risk: LOW (acceptable)

Decision: Transfer APPROVED with supplementary measures
Review Date: 2026-02-01 or upon US legal changes (e.g., new adequacy decision)
```

---

### 6.5.6 - Real-Time Adequacy Monitoring (NEW 2025 Requirement)

**Control:** Monitor adequacy decisions and legal changes in third countries

**Implementation:**
- Subscribe to European Commission adequacy decision updates
- Monitor EDPB guidelines and case law
- Automate alerts (e.g., RSS feed from EC, EDPB)

**Example:** If EU-US DPF is invalidated (like Privacy Shield), controller must:
1. Receive alert (same day)
2. Assess impact (which transfers affected?)
3. Implement fallback (SCCs + TIA) within 30 days
4. Notify supervisory authority if unable to comply

---

## Clause 6.6: Privacy by Design and Default (Enhanced 2025)

### 6.6.1 - Privacy by Design (GDPR Art. 25(1) - Engineering Principles 2025)

**Control:** Implement privacy by design at time of determining means of processing

**7 Foundational Principles (Cavoukian):**
1. **Proactive not Reactive** - Anticipate privacy risks before they occur
2. **Privacy as Default** - No action required from data subject to protect privacy
3. **Privacy Embedded into Design** - Integral to system, not add-on
4. **Full Functionality** - Positive-sum (privacy AND functionality, not trade-off)
5. **End-to-End Security** - Protect throughout data lifecycle
6. **Visibility and Transparency** - Open and accountable
7. **Respect for User Privacy** - User-centric

**Engineering Techniques (2025):**
- **Minimization** - Collect minimal data (don't collect if not needed, aggregate/anonymize if possible)
- **Hiding** - Encryption, pseudonymization, mix networks, onion routing
- **Separating** - Distributed processing, sharding, multi-party computation
- **Aggregating** - Statistical disclosure control, differential privacy, k-anonymity
- **Informing** - Transparency logs, privacy dashboards, explanations
- **Controlling** - Consent management, preference centers, granular settings
- **Enforcing** - Access control, obligation management, usage policies
- **Demonstrating** - Audit logs, compliance reports, certifications

**Example:**
```
System: Employee Performance Monitoring Tool

Privacy by Design Implementation:

1. Minimization:
   - Collect only work-related metrics (tasks completed, hours logged)
   - Do NOT collect keystrokes, screenshots, webcam (excessive)

2. Hiding:
   - Pseudonymize employee IDs in analytics database
   - Encrypt performance data at rest (AES-256)

3. Separating:
   - Aggregate performance metrics stored separately from personal data (names, emails)
   - Join only when necessary (e.g., individual performance review)

4. Aggregating:
   - Team-level reports use aggregated data (no individual identification)
   - Minimum team size for aggregation: 5 employees (k-anonymity k=5)

5. Informing:
   - Employee dashboard shows what data is collected (transparency)
   - Privacy notice clearly explains monitoring purposes and metrics

6. Controlling:
   - Employees can request exclusion from optional benchmarking (opt-out)
   - Managers require specific justification to access individual reports (purpose limitation)

7. Enforcing:
   - Role-based access control (RBAC) - only direct manager + HR can view individual data
   - Audit log tracks all access to performance data

8. Demonstrating:
   - DPIA completed (medium risk, acceptable with safeguards)
   - Quarterly privacy audit (compliance verification)
```

---

### 6.6.2 - Privacy by Default (GDPR Art. 25(2))

**Control:** Ensure by default only PII necessary for specific purpose is processed

**Requirements:**
- **Amount** - Only necessary data
- **Extent** - Only for specific purpose (not general collection)
- **Period** - Only as long as necessary
- **Accessibility** - Only by those who need access

**Example:**
```
Social Media Platform - Privacy by Default:

Default Settings (Out-of-the-Box):
✅ Profile visibility: Friends only (not public)
✅ Post visibility: Friends (user can change to public per post)
✅ Location sharing: Off
✅ Ad personalization: Minimal (only based on current session, not full profile)
✅ Data retention: Inactive accounts deleted after 2 years
✅ Third-party app access: Require explicit consent (no pre-authorized apps)

User MUST actively opt-in for:
- Public profile
- Location tracking
- Full ad personalization (behavioral targeting)
- Longer data retention
- Third-party data sharing

Rationale: Most privacy-protective settings by default, user can choose to relax if desired.
```

---

### 6.6.3 - Pseudonymization (GDPR Art. 25, 32(1)(a) - Enhanced 2025)

**Control:** Pseudonymize PII where feasible

**Definition:** Processing such that PII can no longer be attributed to specific data subject without additional information (kept separately, under technical/organizational measures)

**Techniques:**
- **Tokenization** - Replace PII with random token (lookup table secured)
- **Hashing** - One-way hash function (e.g., SHA-256 of email)
- **Encryption** - Symmetric/asymmetric encryption (key management critical)
- **Data Masking** - Partial redaction (e.g., show last 4 digits of SSN: xxx-xx-1234)

**Benefits:**
- Reduces risk if data breached (pseudonyms not directly identifying)
- Enables data analysis without exposing identities
- Counts as "appropriate security measure" (GDPR Art. 32)

**Limitations:**
- NOT anonymization (still PII if re-identification possible with additional info)
- Key/mapping table must be secured (otherwise defeats purpose)

**Example:**
```
Analytics Platform - Pseudonymization:

Original Data:
| Name       | Email              | Page Views |
|------------|--------------------|------------|
| Jane Smith | jane@example.com   | 120        |
| John Doe   | john@company.org   | 85         |

Pseudonymized Data:
| User_ID (Pseudonym)       | Page Views |
|---------------------------|------------|
| a3f5e8c9d1b2             | 120        |
| 7d4b9e2f1a6c             | 85         |

Mapping Table (Secured Separately):
| Pseudonym    | Email (Encrypted) |
|--------------|-------------------|
| a3f5e8c9d1b2 | [AES-256 blob]   |
| 7d4b9e2f1a6c | [AES-256 blob]   |

Access Control:
- Analytics team: Access to pseudonymized data only
- Customer support: Access to mapping table (to link pseudonym to real identity for support tickets)
- Encryption keys: Held by DPO (not accessible to analytics team)

Result: Analytics can run reports without knowing user identities
```

---

### 6.6.4 - Anonymization (GDPR Recital 26 - Techniques 2025)

**Control:** Anonymize PII when identifiability no longer necessary

**Definition:** PII rendered anonymous such that data subject is no longer identifiable (GDPR no longer applies)

**Anonymization Techniques:**
- **Aggregation** - Combine multiple records (e.g., average age of customers)
- **K-Anonymity** - Each record indistinguishable from at least k-1 other records
- **L-Diversity** - Sensitive attributes have at least l distinct values per group
- **T-Closeness** - Distribution of sensitive attribute in group close to overall distribution
- **Differential Privacy** - Add statistical noise (guarantees individual records not identifiable)

**Example (K-Anonymity k=3):**
```
Medical Records - Anonymization:

Original Data:
| Name       | Age | Zipcode | Diagnosis      |
|------------|-----|---------|----------------|
| Jane Smith | 35  | 10001   | Diabetes       |
| John Doe   | 37  | 10001   | Hypertension   |
| Alice Bob  | 34  | 10002   | Diabetes       |
| Bob Alice  | 36  | 10002   | Cancer         |
| Eve Mal    | 38  | 10003   | Hypertension   |

K-Anonymized Data (k=3):
| Age Range | Zipcode Range | Diagnosis      | Count |
|-----------|---------------|----------------|-------|
| 30-40     | 10001-10003   | Diabetes       | 2     |
| 30-40     | 10001-10003   | Hypertension   | 2     |
| 30-40     | 10001-10003   | Cancer         | 1     |

Result: Each record indistinguishable from at least 2 others (k=3)
Can publish for research without identifying individuals
```

**Caution:** Anonymization is irreversible. Once anonymized, data cannot be linked back to individuals (e.g., cannot respond to data subject access request for anonymized data).

---

## Clause 6.7: Children's Data (NEW 2025)

**New clause addressing GDPR Art. 8 (Child's consent) and special protections for minors**

### 6.7.1 - Age Verification (GDPR Art. 8)

**Control:** Implement age verification mechanisms for children's data processing

**GDPR Art. 8 Requirements:**
- **Age of consent:** 16 years (Member States may lower to 13, e.g., UK: 13)
- **Below age:** Parental consent required
- **Verification:** "Reasonable efforts" to verify parental consent (considering technology)

**Age Verification Methods (2025 Guidance):**
- **Self-declaration** - Ask date of birth (low assurance, acceptable for low-risk)
- **Credit card** - Verify age via credit card (18+)
- **ID verification** - Government ID scan (high assurance, privacy-intrusive)
- **Neutral age estimation** - AI-based age estimation from selfie (emerging, privacy concerns)
- **Third-party services** - Age verification providers (Yoti, VerifyMyAge)

**Implementation:**
```php
// ProcessingActivity for children's service
$vvt->setDataSubjectCategories(['Children (under 16)']);
$vvt->setAgeVerificationMethod('self_declaration_with_parental_email');
$vvt->setMinimumAge(13); // UK law
$vvt->setParentalConsentRequired(true);
```

---

### 6.7.2 - Parental Consent Verification (GDPR Art. 8(2))

**Control:** Verify parental consent using reasonable efforts

**Verification Methods:**
- **Email confirmation** - Send email to parent (low assurance)
- **Credit card micropayment** - Charge €0.01 and refund (verifies adult with payment method)
- **Document upload** - Parent uploads ID + signed consent form (high assurance)
- **Video call** - Live verification call (highest assurance, least scalable)

**Example Flow:**
```
Child Registration Flow (Social Media for Kids):

1. Child enters date of birth → System detects age 12 (below 13 threshold)
2. System requests parent email
3. Email sent to parent:
   "Your child (email: child@example.com) wants to create account.
    Review privacy notice and consent:
    [Link to child-appropriate privacy notice]
    Click here to provide parental consent: [Unique consent link]"
4. Parent clicks link → Verification page (upload ID or credit card verification)
5. Parent consents → Account activated
6. Consent recorded: timestamp, parent email, verification method, IP address

Withdrawal: Parent can withdraw consent anytime → Account deleted within 24h
```

---

### 6.7.3 - Child-Appropriate Privacy Notices (NEW 2025)

**Control:** Provide age-appropriate privacy information for children

**Requirements:**
- **Plain language** - No legalese (readable by child)
- **Visual aids** - Icons, illustrations, videos
- **Shorter** - Layered approach (summary + full notice)
- **Interactive** - Quizzes to test understanding

**Example:**
```
Privacy Notice for Kids (Age 8-12):

[Friendly mascot icon]

Title: "How We Keep Your Information Safe"

Section 1: What We Collect
[Icon: Camera] Photos you share
[Icon: Message] Messages to friends
[Icon: Location pin] Where you play our games

Section 2: How We Use It
[Icon: Star] To make the app fun for you
[Icon: Shield] To keep you safe from strangers
[Icon: Graph] To make the app better

Section 3: Who We Share With
[Icon: Parent] Your parents (they can see everything!)
[Icon: X] We DON'T share with advertisers
[Icon: X] We DON'T sell your info

Section 4: Your Choices
[Icon: Trash] You can delete your account anytime
[Icon: Lock] You can make your profile private
[Icon: Question] Ask your parent if you have questions

[Button: "Ask Parent to Explain More"]
[Button: "I Understand" - Links to full privacy notice]

Quiz: "Can we sell your information to toy companies?"
→ Correct answer: "No!" → Proceed to registration
```

---

### 6.7.4 - Special Protections for Children (Recital 38)

**Control:** Implement enhanced protections for children's data

**Enhanced Protections:**
- **No profiling for marketing** (Recital 71) - Children's PII should not be used for marketing/profiling
- **Shorter retention** - Delete children's data when no longer necessary (e.g., delete account + data immediately upon request)
- **No monetization** - Do not use children's data for commercial purposes (e.g., no targeted ads)
- **Safety features** - Stranger danger warnings, reporting mechanisms, parental controls

**Example:**
```
Kids' Online Game - Special Protections:

✅ No targeted advertising (only age-appropriate generic ads)
✅ Profile private by default (only friends can see)
✅ Parental dashboard (parents can view child's activity, messages)
✅ Stranger danger warning (if adult tries to contact child → alert + block)
✅ Report button (prominent "Report Bad Behavior" button)
✅ Data deletion (immediate deletion upon parent request, no retention)
✅ Age-up (when child turns 13 → transition to teen version with full privacy notice)
```

---

### 6.7.5 - Transition to Adult Status (NEW 2025 Guidance)

**Control:** Transition children to adult privacy settings when reaching age of consent

**Workflow:**
```
Age-Up Workflow (Child Turns 16):

1. System detects 16th birthday (from date of birth)
2. Email to now-adult user:
   "Happy Birthday! You're now 16 (adult for data protection purposes).

   Your account has been transitioned:
   - Parental consent no longer required
   - You now control your own privacy settings
   - Please review and update your privacy settings: [Link]"

3. Email to parent (if parental consent was on file):
   "Your child is now 16 (adult for data protection).
    We will no longer share their account information with you unless they choose to allow it.
    Your parental consent record has been archived."

4. Account settings updated:
   - Parental controls removed
   - Privacy settings reset to adult defaults (review required)
   - New privacy notice (adult version) presented

5. Re-consent:
   - User must re-consent to data processing (fresh start as adult)
   - Opportunity to delete historical data from childhood
```

---

## Clause 7: PIMS Incident Management (NEW 2025)

**New clause** provides **privacy-specific incident response guidance** (complements ISO 27035)

### 7.1 Privacy Incident Definition

**Privacy Incident:** Breach, loss, alteration, unauthorized disclosure, or access to PII

**Types:**
- **Data Breach** (GDPR Art. 33/34) - Loss of confidentiality, integrity, availability
- **Data Loss** - Accidental deletion, lost devices
- **Unauthorized Access** - Hacking, insider threat
- **Unauthorized Disclosure** - Sent to wrong recipient, published by mistake
- **Data Quality Incident** - Inaccurate data causing harm to data subjects

---

### 7.2 72-Hour Breach Notification (GDPR Art. 33)

**Control:** Notify supervisory authority within 72 hours of becoming aware of breach (unless unlikely to result in risk to rights/freedoms)

**Workflow:**
```
Breach Notification Workflow:

Hour 0: Breach Detected
├─ Incident Response Team activated
├─ Initial containment (stop breach spreading)
└─ Start clock (72h countdown)

Hour 1-24: Assessment
├─ Determine: PII involved? How many data subjects? What categories?
├─ Assess risk: Likelihood and severity of impact on data subjects
└─ Decision: Notify authority? (if risk to rights/freedoms)

Hour 24: Notify Authority (if required)
├─ Use supervisory authority's online form (e.g., LfDI Baden-Württemberg)
├─ Provide: Nature of breach, affected data subjects, likely consequences, measures taken
└─ Document: Notification timestamp, authority reference number

Hour 24-72: Investigation
├─ Root cause analysis
├─ Detailed impact assessment
└─ Develop remediation plan

Hour 72: Deadline
├─ If not notified by Hour 72 → Document reason for delay (Art. 33(1))
└─ If notified → Provide follow-up information as investigation progresses

Post-72h: Follow-Up
├─ Notify authority of additional findings
├─ Implement lessons learned
└─ Update incident response plan
```

---

### 7.3 Data Subject Notification (GDPR Art. 34)

**Control:** Notify data subjects without undue delay if breach likely to result in high risk to rights/freedoms

**High Risk Examples:**
- Financial loss (credit card data stolen)
- Identity theft (SSN, passport numbers)
- Discrimination (sensitive categories - Art. 9)
- Reputational damage (embarrassing data disclosed)

**Exceptions (Art. 34(3) - DO NOT notify if):**
1. **Encryption** - Data encrypted and key not compromised (data unreadable)
2. **Subsequent measures** - Controller took measures ensuring high risk no longer likely (e.g., reached out to recipient and confirmed deletion)
3. **Disproportionate effort** - Would require disproportionate effort → Public communication instead (e.g., 10 million affected, no contact info)

**Notification Content:**
- Nature of breach
- Contact point (DPO)
- Likely consequences
- Measures taken/proposed
- Recommendations (e.g., change password, monitor credit)

**Example:**
```
Data Breach Notification to Data Subjects:

Subject: Important Security Notice About Your Account

Dear [Name],

We are writing to inform you of a security incident that may affect your personal information.

What Happened:
On November 20, 2025, we discovered that an unauthorized party gained access to our customer database through a vulnerability in our web application.

What Information Was Involved:
Your name, email address, and order history were potentially accessed. Payment information (credit card numbers) were NOT affected as they are stored in a separate, secure system.

What We Are Doing:
- We have closed the vulnerability and enhanced our security measures
- We are working with cybersecurity experts to investigate
- We have notified the relevant data protection authority

What You Can Do:
- Be cautious of phishing emails (verify sender before clicking links)
- Monitor your account for unusual activity
- Change your password as a precaution (use unique, strong password)

More Information:
For questions, contact our Data Protection Officer at dpo@company.com or call 1-800-XXX-XXXX.

We sincerely apologize for this incident and the concern it may cause.

Sincerely,
[Company Name]
```

---

## Clause 8: PIMS Monitoring and Measurement (NEW 2025)

**New clause** provides **privacy metrics and KPIs** for PIMS effectiveness monitoring

### 8.1 Privacy KPIs (Key Performance Indicators)

**PIMS Maturity KPIs:**
- **VVT Completeness** - % of processing activities documented (target: 100%)
- **DPIA Coverage** - % of high-risk processing with completed DPIA (target: 100%)
- **Consent Withdrawal Rate** - % of consents withdrawn (lower is better, but also indicates transparency)
- **Data Subject Request Response Time** - Average days to respond (target: <15 days, legal max: 30 days)
- **Privacy Training Completion** - % of employees trained annually (target: 95%)
- **Breach Notification Timeliness** - % of breaches notified within 72h (target: 100%)

**Example KPI Dashboard:**
```
PIMS Performance Dashboard - Q4 2025:

Processing Activities:
✅ Documented: 42/42 (100%)
✅ Complete (all mandatory fields): 40/42 (95%)
⚠️ Requiring DPIA: 5/42 (12%)
✅ DPIA Completed: 5/5 (100%)

Data Subject Rights:
📊 Requests received: 127 (Access: 89, Erasure: 23, Rectification: 10, Other: 5)
✅ Average response time: 12 days (target: <15 days)
✅ Within deadline (30 days): 127/127 (100%)
❌ Overdue: 0

Consents:
📊 Active consents: 50,000
📊 Withdrawals this quarter: 1,250 (2.5% of active)
✅ Withdrawal processing time: <1 day (target: <2 days)

Data Breaches:
⚠️ Breaches this year: 2
✅ Authority notifications: 1/1 (100% - only 1 required notification)
✅ Within 72h: 1/1 (100%)
✅ Subject notifications: 0 (no high-risk breaches)

Training:
✅ Employees trained: 485/500 (97%)
⏰ Completion deadline: 2025-12-31

Overall PIMS Maturity: Level 4/5 (Managed) - Target: Level 5 (Optimizing)
```

---

### 8.2 Privacy KRIs (Key Risk Indicators)

**Leading Indicators (Early Warning):**
- **Consent Opt-Out Rate Trend** - Increasing opt-outs may signal privacy concerns
- **Data Subject Complaint Rate** - Complaints per 1,000 data subjects
- **Third-Party Processor Incidents** - Breaches at processors (supply chain risk)
- **Regulatory Updates** - New laws, EDPB guidelines, case law
- **Shadow IT Discovery** - Unauthorized tools processing PII

**Example:**
```
Privacy KRI Alert - November 2025:

🚨 KRI Threshold Breach:

KRI: Consent Opt-Out Rate (Marketing Emails)
Threshold: <5% monthly opt-out rate
Current: 8.5% (trend: increasing for 3 consecutive months)

Analysis:
- October: 4.2% opt-out
- November: 8.5% opt-out (+102% increase)
- Possible causes:
  * Increased email frequency (2x/week → 4x/week in November)
  * Black Friday campaign (5 emails in 1 week)
  * Competitor offering better privacy (privacy-focused email competitor launched)

Action Plan:
1. Reduce email frequency immediately (back to 2x/week)
2. Survey opted-out users (understand reasons)
3. Review email personalization (too creepy?)
4. Enhance transparency (clearer privacy notice)

Review: 2025-12-15 (monitor if opt-out rate decreases)
```

---

## Key Definitions (ISO 27701:2025)

**PII (Personally Identifiable Information):** Any information relating to an identified or identifiable natural person (same as GDPR "personal data")

**PII Controller:** Entity which determines purposes and means of processing PII (GDPR "controller")

**PII Processor:** Entity which processes PII on behalf of controller (GDPR "processor")

**PIMS (Privacy Information Management System):** Management system for privacy, extending ISO 27001 ISMS

**Data Subject:** Identified or identifiable natural person (GDPR term)

**Consent:** Freely given, specific, informed, unambiguous indication of wishes (GDPR Art. 4(11))

**Pseudonymization:** Processing such that PII can no longer be attributed to specific data subject without additional information (GDPR Art. 4(5))

**Anonymization:** Irreversible process rendering data subject no longer identifiable (not defined in GDPR, in Recital 26)

**Data Protection Impact Assessment (DPIA):** Assessment of impact of processing on protection of personal data (GDPR Art. 35)

**Data Protection Officer (DPO):** Person designated to oversee GDPR compliance (GDPR Art. 37-39)

---

## ISO 27701:2025 vs. 2019 Changes Summary

| Aspect | ISO 27701:2019 | ISO 27701:2025 (NEW) |
|--------|----------------|----------------------|
| **Controller Controls** | 27 controls | 34 controls (+7 new) |
| **Processor Controls** | 12 controls | 12 controls (refined) |
| **AI Controls** | Not addressed | 8 new controls (Clause 6.4) |
| **Children's Data** | Brief mention | Dedicated clause (6.7) with 5 controls |
| **Cross-Border Transfers** | Basic guidance | Enhanced (Schrems II, TIA mandatory) |
| **Consent Management** | Basic requirements | Granular, withdrawal automation |
| **Privacy by Design** | General principles | Engineering techniques, PETs |
| **Incident Management** | Referenced ISO 27035 | Dedicated clause (7) with workflows |
| **Privacy Metrics** | Not specified | KPIs and KRIs framework (Clause 8) |
| **DPIA** | Basic template | Enhanced with AI impact, TIA |
| **Data Subject Rights** | Manual processes acceptable | Automation recommended |

---

## ISO 27701:2025 Compliance Checklist

**Clause 5: PIMS Framework**
- ☐ Privacy context documented (external/internal factors)
- ☐ Privacy governance structure (DPO, Privacy Committee)
- ☐ Privacy objectives and policy approved by top management
- ☐ Privacy competence and training program
- ☐ DPIA process established and documented
- ☐ Privacy monitoring and metrics (KPIs, KRIs)

**Clause 6.2: Controller Controls**
- ☐ Legal basis identified and documented per processing (6.2.1)
- ☐ Consent obtained and recorded (6.2.2)
- ☐ Consent withdrawal mechanism (6.2.3)
- ☐ Legitimate Interest Assessment (LIA) for Art. 6(1)(f) (6.2.4)
- ☐ Data minimization and purpose limitation (6.2.5-6.2.7)
- ☐ Data subject rights procedures (6.2.8-6.2.15)
  - ☐ Access (Art. 15)
  - ☐ Rectification (Art. 16)
  - ☐ Erasure (Art. 17)
  - ☐ Restriction (Art. 18)
  - ☐ Portability (Art. 20)
  - ☐ Objection (Art. 21)
  - ☐ Automated decision-making opt-out (Art. 22)
- ☐ Processor agreements with Art. 28(3) clauses (6.2.16)
- ☐ Sub-processor management (6.2.17)
- ☐ Joint controller arrangements (6.2.18)
- ☐ Privacy notices (Art. 13, 14) (6.2.20)
- ☐ Cookie/tracking consent (6.2.21)

**Clause 6.3: Processor Controls (if applicable)**
- ☐ Process only on documented instructions (6.3.1)
- ☐ Confidentiality obligations (6.3.2)
- ☐ Security measures (Art. 32) (6.3.3)
- ☐ Assist controller with data subject rights (6.3.5)
- ☐ Assist controller with DPIA (6.3.6)
- ☐ Return/delete PII after services end (6.3.7)
- ☐ Data breach notification to controller (6.3.10)
- ☐ Processor records of processing (Art. 30(2)) (6.3.11)

**Clause 6.4: AI Controls (if applicable)**
- ☐ Transparency in automated decision-making (6.4.1)
- ☐ Human-in-the-loop for significant decisions (6.4.2)
- ☐ AI explainability (6.4.3)
- ☐ Algorithmic bias detection and mitigation (6.4.4)
- ☐ AI model governance (6.4.5)

**Clause 6.5: Cross-Border Transfers (if applicable)**
- ☐ Adequacy decision verified (6.5.1)
- ☐ SCCs implemented with annexes (6.5.2)
- ☐ Transfer Impact Assessment (TIA) completed (6.5.5)
- ☐ Supplementary measures implemented if needed (6.5.5)
- ☐ Real-time adequacy monitoring (6.5.6)

**Clause 6.6: Privacy by Design**
- ☐ Privacy by design principles embedded (6.6.1)
- ☐ Privacy by default settings (6.6.2)
- ☐ Pseudonymization implemented where feasible (6.6.3)
- ☐ Anonymization for statistical purposes (6.6.4)

**Clause 6.7: Children's Data (if applicable)**
- ☐ Age verification mechanism (6.7.1)
- ☐ Parental consent verification (6.7.2)
- ☐ Child-appropriate privacy notices (6.7.3)
- ☐ Special protections (no profiling for marketing) (6.7.4)
- ☐ Age-up transition workflow (6.7.5)

**Clause 7: Incident Management**
- ☐ 72-hour breach notification process (7.2)
- ☐ Data subject notification criteria (7.3)
- ☐ Breach documentation and lessons learned

**Clause 8: Monitoring**
- ☐ Privacy KPIs defined and tracked (8.1)
- ☐ Privacy KRIs monitored (8.2)
- ☐ Regular PIMS performance reviews

---

## Summary

ISO 27701:2025 is the **latest Privacy Information Management System (PIMS) standard**, extending ISO 27001:2022 with comprehensive privacy controls for **GDPR compliance**.

**Key Enhancements in 2025:**
1. **AI and Automated Decision-Making** (NEW Clause 6.4) - Explainability, bias detection, human-in-the-loop
2. **Children's Data** (NEW Clause 6.7) - Age verification, parental consent, special protections
3. **Cross-Border Transfers** (Enhanced 6.5) - Transfer Impact Assessments (TIA) mandatory post-Schrems II
4. **Privacy Metrics** (NEW Clause 8) - KPIs and KRIs framework
5. **Incident Management** (NEW Clause 7) - 72h breach notification workflows
6. **Enhanced Controller Controls** (6.2) - Consent automation, data subject rights automation
7. **Privacy by Design** (Enhanced 6.6) - Engineering techniques, PETs

**Coverage:**
- **34 Controller Controls** (GDPR Art. 5-34)
- **12 Processor Controls** (GDPR Art. 28-32)
- **8 AI Controls** (GDPR Art. 22)
- **6 Cross-Border Transfer Controls** (GDPR Art. 44-49)
- **10 Privacy by Design Controls** (GDPR Art. 25)
- **5 Children's Data Controls** (GDPR Art. 8)

**Relationship to GDPR:**
ISO 27701:2025 provides **implementation guidance** for GDPR. Compliance with ISO 27701 demonstrates GDPR compliance (Art. 24, 25, 32 - appropriate measures). 🎯

**Certification:** Organizations can obtain ISO 27701 certification (extends ISO 27001 certificate), demonstrating privacy maturity to customers, regulators, partners.
