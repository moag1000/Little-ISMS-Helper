# ISO 27005:2022 - Information Security Risk Management
## Quick Reference for Risk Management Specialist

### Standard Overview

**Full Title:** ISO/IEC 27005:2022 - Information security, cybersecurity and privacy protection — Guidance on managing information security risks

**Purpose:** Provides guidelines for information security risk management that supports the general concepts specified in ISO/IEC 27001

**Relationship to ISO 27001:** ISO 27005 provides the detailed methodology for implementing ISO 27001 Clause 6.1.2 (Risk Assessment) and 6.1.3 (Risk Treatment)

**Latest Version:** ISO/IEC 27005:2022 (published 2022-10)
**Previous Version:** ISO/IEC 27005:2018 (superseded)

### Key Changes in 2022 Edition

**Major Updates:**
1. **Alignment with ISO 31000:2018** - Harmonized terminology and structure
2. **Enhanced supply chain risk** - New guidance on third-party and supplier risks
3. **Cloud security risks** - Specific guidance for cloud computing environments
4. **Privacy risk integration** - Better alignment with ISO 27701 (privacy)
5. **Simplified annexes** - Reorganized threat/vulnerability/control examples
6. **Digital transformation** - IoT, AI, remote work considerations

---

## Standard Structure

### Clause 1: Scope
Defines applicability to all types and sizes of organizations

### Clause 2: Normative References
- ISO/IEC 27000 (Vocabulary)
- ISO/IEC 27001 (Requirements)

### Clause 3: Terms and Definitions
Key terms (see Definitions section below)

### Clause 4: Background
Overview of information security risk management concepts

### Clause 5: Overview of Information Security Risk Management Process
High-level process flow

### Clause 6: Information Security Risk Management Process (MAIN CONTENT)
Detailed methodology:
- 6.2: Context Establishment
- 6.3: Risk Assessment
  - 6.3.1: Risk Identification
  - 6.3.2: Risk Analysis
  - 6.3.3: Risk Evaluation
- 6.4: Risk Treatment
  - 6.4.1: Risk Treatment Option Selection
  - 6.4.2: Risk Treatment Plan
  - 6.4.3: Residual Risk Assessment
  - 6.4.4: Risk Acceptance
- 6.5: Risk Communication and Consultation
- 6.6: Risk Monitoring and Review

### Annexes (Informative)
- **Annex A**: Defining the scope and boundaries
- **Annex B**: Examples of typical assets
- **Annex C**: Examples of threats
- **Annex D**: Examples of vulnerabilities
- **Annex E**: Information security risk assessment approaches (qualitative, quantitative, hybrid)

---

## Clause 6: Information Security Risk Management Process

### 6.1 General

**Purpose:** Establish systematic approach to manage information security risks

**Process Overview:**
```
┌─────────────────────────────────────────────────────┐
│           Context Establishment (6.2)                │
│  • Scope, criteria, organization, external context  │
└────────────────┬────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────┐
│            Risk Assessment (6.3)                     │
│  ┌─────────────────────────────────────────────┐   │
│  │  6.3.1: Risk Identification                  │   │
│  │  • Assets, threats, vulnerabilities          │   │
│  └─────────────┬───────────────────────────────┘   │
│                ▼                                     │
│  ┌─────────────────────────────────────────────┐   │
│  │  6.3.2: Risk Analysis                        │   │
│  │  • Impact assessment, likelihood assessment  │   │
│  └─────────────┬───────────────────────────────┘   │
│                ▼                                     │
│  ┌─────────────────────────────────────────────┐   │
│  │  6.3.3: Risk Evaluation                      │   │
│  │  • Compare against criteria, prioritize      │   │
│  └─────────────────────────────────────────────┘   │
└────────────────┬────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────┐
│            Risk Treatment (6.4)                      │
│  • Select options, plan, assess residual, accept    │
└────────────────┬────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────┐
│  Risk Communication & Consultation (6.5) ◄──────────┤
│  Risk Monitoring & Review (6.6)                     │
└─────────────────────────────────────────────────────┘
         (Continuous, iterative)
```

---

### 6.2 Context Establishment

**Purpose:** Define scope, criteria, and organization for risk management

#### 6.2.1 General Considerations

**Inputs:**
- Organization's strategic objectives
- Legal, regulatory, contractual requirements
- Stakeholder expectations
- External environment (threat landscape, geopolitical, economic)
- Internal context (governance, culture, processes, IT architecture)

**Outputs:**
- Risk management scope
- Risk criteria (impact, likelihood scales)
- Risk evaluation criteria (acceptance thresholds)
- Organization roles and responsibilities

#### 6.2.2 Basic Criteria for Information Security Risk Management

**Risk Criteria Must Include:**

1. **Impact Criteria** - Define scales for consequences:
   - **Financial**: Monetary loss, fines, penalties
   - **Reputational**: Brand damage, customer trust loss
   - **Operational**: Business disruption, productivity loss
   - **Legal/Regulatory**: Non-compliance, license revocation
   - **Strategic**: Competitive disadvantage, market share loss

2. **Likelihood Criteria** - Define probability scales:
   - Frequency-based (e.g., incidents per year)
   - Probability-based (e.g., percentage chance)
   - Qualitative (e.g., Rare, Unlikely, Possible, Likely, Almost Certain)

3. **Risk Evaluation Criteria** - Define acceptance thresholds:
   - **Risk Appetite**: Maximum risk willing to accept to achieve objectives
   - **Risk Tolerance**: Acceptable deviation around risk appetite
   - **Risk Capacity**: Maximum risk organization can bear before viability threatened

**Example Risk Matrix (5×5):**
```
Impact →        1           2           3           4           5
Likelihood ↓   Negligible  Minor       Moderate    Major       Critical
---------------------------------------------------------------------------
5 (Almost      5           10          15          20          25
  Certain)    (Medium)    (High)      (High)    (Critical)  (Critical)

4 (Likely)     4           8           12          16          20
              (Low)      (Medium)     (High)      (High)    (Critical)

3 (Possible)   3           6           9           12          15
              (Low)      (Medium)   (Medium)     (High)      (High)

2 (Unlikely)   2           4           6           8           10
              (Low)       (Low)     (Medium)    (Medium)     (High)

1 (Rare)       1           2           3           4           5
              (Low)       (Low)      (Low)       (Low)      (Medium)
```

**Risk Levels:**
- **1-3**: Low (Green) - Accept and monitor
- **4-9**: Medium (Yellow) - Reduce, monitor, or transfer
- **10-15**: High (Orange) - Reduce or transfer, management attention
- **16-25**: Critical (Red) - Immediate action, senior management/board escalation

#### 6.2.3 Scope and Boundaries

**Define Scope:**
- **Physical Scope**: Locations, facilities, data centers
- **Organizational Scope**: Departments, business units, subsidiaries
- **Logical Scope**: Information systems, networks, applications, data
- **Temporal Scope**: Time period for risk assessment (e.g., 1 year, 3 years)

**Define Boundaries:**
- **Internal/External**: What is inside vs. outside control?
- **In-Scope/Out-of-Scope**: What is included/excluded?
- **Interfaces**: Where does your scope interact with external entities (suppliers, partners, cloud providers)?

**Example Scope Statement:**
> "Risk assessment covers all information assets supporting the Customer Management System (CMS), including:
> - 3 application servers (on-premises data center)
> - 2 database servers (AWS eu-central-1)
> - Customer data (50,000 records, GDPR personal data)
> - 15 employees with access
> - Integration with payment gateway (third-party, out-of-scope but interface considered)
>
> Time period: 12 months (annual risk assessment)
> Excluses: Marketing website (separate low-risk assessment)"

#### 6.2.4 Organization for Information Security Risk Management

**Define Roles:**
- **Risk Owner**: Person accountable for managing specific risk (typically business process owner)
- **Asset Owner**: Person responsible for information asset
- **Risk Manager**: Facilitates risk management process
- **Risk Analyst**: Performs risk assessments
- **Information Security Manager**: Oversees ISMS, approves risk treatment
- **Senior Management**: Approves risk acceptance, provides resources
- **Board/Executive**: Approves high-level risk appetite, reviews critical risks

**Assign Responsibilities:**
- Who identifies risks?
- Who assesses impact and likelihood?
- Who approves risk treatment plans?
- Who accepts residual risks?
- Who monitors risk treatment progress?

---

### 6.3 Risk Assessment

**Purpose:** Identify, analyze, and evaluate information security risks

**Overall Process:**
1. **Identify** what can happen (threats, vulnerabilities)
2. **Analyze** consequences (impact) and probability (likelihood)
3. **Evaluate** whether risk is acceptable or requires treatment

#### 6.3.1 Risk Identification

**Purpose:** Find, recognize, and describe risks that might affect objectives

##### a) Asset Identification

**Asset Types (ISO 27005 Annex B):**

**Primary Assets:**
1. **Information Assets**
   - Business data (customer data, financial records, IP)
   - System data (logs, configurations, authentication data)
   - Project data (designs, specifications, source code)
   - Backup data

2. **Business Processes and Activities**
   - Core processes (order processing, manufacturing, customer service)
   - Support processes (HR, accounting, IT support)
   - Management processes (strategic planning, risk management)

**Supporting Assets:**
3. **Hardware Assets**
   - Servers (physical, virtual)
   - Network equipment (routers, switches, firewalls)
   - End-user devices (laptops, desktops, mobile)
   - Storage systems (NAS, SAN, cloud storage)
   - Removable media (USB drives, external HDDs)

4. **Software Assets**
   - Operating systems
   - Applications (ERP, CRM, custom applications)
   - Databases
   - Security software (antivirus, IDS/IPS, SIEM)
   - Development tools

5. **Network Assets**
   - LAN/WAN infrastructure
   - Internet connections
   - VPN tunnels
   - Wireless networks
   - DMZ zones

6. **Facilities Assets**
   - Buildings, offices
   - Data centers
   - Server rooms
   - Utility systems (power, cooling, water)

7. **Personnel Assets**
   - Employees (permanent, temporary, contractors)
   - Administrators (IT, security, database)
   - Management
   - Third-party personnel (vendors, consultants)

8. **Organization Assets**
   - Reputation, brand
   - Organizational structure
   - Policies, procedures
   - Contractual relationships

**Asset Attributes to Document:**
- **Name and Description**: Clear identification
- **Owner**: Person responsible
- **Location**: Physical/logical location
- **Type**: Hardware, software, data, etc.
- **CIA Requirements**: Confidentiality, Integrity, Availability ratings (e.g., 1-5)
- **Value**: Monetary value or criticality rating
- **Dependencies**: What does this asset depend on? What depends on it?

##### b) Threat Identification

**Threat:** Potential cause of an unwanted incident

**Threat Categories (ISO 27005 Annex C):**

**1. Human Threats (Deliberate)**
- **External Attackers**: Hackers, cybercriminals, nation-states, competitors, hacktivists
  - Examples: Unauthorized access, malware, phishing, DDoS, SQL injection, ransomware
- **Internal Malicious**: Disgruntled employees, insider threats
  - Examples: Data theft, sabotage, fraud, espionage

**2. Human Threats (Accidental)**
- **Errors**: Human mistakes, negligence
  - Examples: Misconfiguration, accidental deletion, sending email to wrong recipient
- **Lack of Awareness**: Untrained users
  - Examples: Falling for phishing, weak passwords, leaving workstation unlocked

**3. Natural Threats**
- **Environmental**: Fire, flood, earthquake, hurricane, tornado, tsunami
- **Pandemic**: Disease outbreak affecting workforce availability

**4. Physical Threats**
- **Theft**: Laptop theft, USB drive theft, document theft
- **Damage**: Physical damage to equipment (accidental or deliberate)
- **Unauthorized Access**: Intruders entering facilities

**5. Technical Threats**
- **Hardware Failures**: Server failure, disk failure, power supply failure
- **Software Failures**: Application crash, OS bug, database corruption
- **Network Failures**: Router failure, ISP outage, fiber cut
- **Power Failures**: Grid outage, UPS failure, generator failure
- **HVAC Failures**: Cooling system failure causing overheating

**6. Third-Party Threats**
- **Supplier Failures**: Cloud provider outage, SaaS service disruption
- **Supply Chain**: Compromised software/hardware from supplier
- **Outsourcing Risks**: Service provider security breach

**Threat Sources (Who/What?):**
- **Internal**: Employees, contractors, administrators
- **External**: Cybercriminals, competitors, nation-states, hacktivists
- **Natural**: Weather, geological events
- **Environmental**: Utilities, infrastructure
- **Technical**: Systems, equipment

##### c) Vulnerability Identification

**Vulnerability:** Weakness that can be exploited by threats

**Vulnerability Categories (ISO 27005 Annex D):**

**1. Physical Vulnerabilities**
- Inadequate physical access controls (no badges, no CCTV)
- Unsecured facilities (unlocked doors, open windows)
- Inadequate fire suppression
- Single point of failure (one power supply, one ISP)
- Unprotected backup media storage

**2. Technical Vulnerabilities**
- **Software**: Unpatched systems, known CVEs, zero-day vulnerabilities, outdated software (end-of-life)
- **Configuration**: Default passwords, unnecessary services enabled, weak encryption, open ports
- **Architecture**: Lack of network segmentation, no DMZ, flat network, single-tier application
- **Access Control**: Weak passwords, no MFA, excessive privileges, shared accounts

**3. Organizational Vulnerabilities**
- Lack of security policies
- Inadequate security awareness training
- No incident response plan
- Insufficient logging/monitoring
- No backup procedures
- Unclear roles and responsibilities

**4. Process Vulnerabilities**
- Inadequate change management
- No vulnerability management process
- Weak password policy
- No access review process
- Inadequate vendor management

**5. Human Vulnerabilities**
- Lack of security awareness
- Insufficient training
- Social engineering susceptibility
- Insider threat risk (disgruntled employees)

**How to Identify Vulnerabilities:**
1. **Vulnerability Scans**: Automated tools (Nessus, Qualys, OpenVAS)
2. **Penetration Testing**: Ethical hacking to find exploitable weaknesses
3. **Configuration Reviews**: Audit system configurations against baselines (CIS Benchmarks)
4. **Code Reviews**: Static/dynamic analysis of application code
5. **Security Audits**: Review policies, procedures, compliance
6. **Gap Analysis**: Compare current state vs. best practices (ISO 27001, NIST)

##### d) Existing Controls Identification

**Control:** Measure that modifies risk (ISO 27001 Annex A controls)

**Why Identify Existing Controls?**
- Understand current risk posture
- Calculate residual risk (after controls applied)
- Avoid duplicating existing controls
- Identify control gaps

**Control Categories (ISO 27001:2022 Annex A):**
- **Organizational Controls** (37 controls): Policies, organization, HR, compliance
- **People Controls** (8 controls): Awareness, training, disciplinary process
- **Physical Controls** (14 controls): Physical security, access control, equipment
- **Technological Controls** (34 controls): Access control, cryptography, networks, systems

**Control Attributes to Document:**
- **Control ID**: ISO 27001 Annex A reference (e.g., A.5.15, A.8.7)
- **Implementation Status**: Not Implemented, Planned, Partially Implemented, Implemented
- **Effectiveness**: Percentage (e.g., 80% effective) or qualitative (Low, Medium, High)
- **Owner**: Person responsible for control

##### e) Consequences Determination

**Consequence:** Outcome of an event affecting objectives (negative = impact)

**Impact Types:**
1. **Confidentiality Impact** - Unauthorized disclosure
   - Exposure of trade secrets
   - GDPR personal data breach
   - Loss of competitive advantage
   - Legal liability

2. **Integrity Impact** - Unauthorized modification
   - Data corruption
   - Fraudulent transactions
   - Loss of data reliability
   - Regulatory non-compliance

3. **Availability Impact** - Loss of access/functionality
   - System downtime
   - Business process disruption
   - Loss of revenue
   - Customer dissatisfaction
   - SLA breach

**Impact Assessment (per asset):**
- What happens if confidentiality is breached?
- What happens if integrity is compromised?
- What happens if availability is lost?

**Impact Scales (Qualitative - 5 levels):**

| Level | Label | Description | Financial | Example |
|-------|-------|-------------|-----------|---------|
| **1** | **Negligible** | Minimal impact, no significant consequences | < €1,000 | Minor inconvenience, local workaround available |
| **2** | **Minor** | Limited impact, contained consequences | €1k-€10k | Temporary disruption, some customer complaints |
| **3** | **Moderate** | Significant impact, noticeable consequences | €10k-€100k | Extended downtime, regulatory warning, media attention |
| **4** | **Major** | Severe impact, serious consequences | €100k-€1M | Major disruption, regulatory fines, significant revenue loss |
| **5** | **Critical** | Catastrophic impact, threatens viability | > €1M | Business survival threatened, massive breach, license loss |

**Impact Scoring Example:**
```
Asset: Customer Database (50,000 records)

Confidentiality Breach:
- Financial: €500k (GDPR fines, legal costs)
- Reputational: High (customer trust loss, media coverage)
- Legal: High (GDPR Art. 33 breach notification, Art. 82 liability)
→ Impact Level: 5 (Critical)

Integrity Compromise:
- Financial: €200k (incorrect billing, fraudulent transactions)
- Operational: High (business decisions based on bad data)
→ Impact Level: 4 (Major)

Availability Loss:
- Financial: €50k/day (lost revenue, SLA penalties)
- Operational: High (cannot serve customers)
→ Impact Level: 4 (Major)
```

#### 6.3.2 Risk Analysis

**Purpose:** Understand the nature of risk and determine level of risk

##### a) Risk Assessment Methodologies

**Three Approaches (ISO 27005 Annex E):**

**1. Qualitative Risk Assessment**
- **Method**: Use descriptive scales (Low/Medium/High, 1-5 ratings)
- **Advantages**: Fast, easy to understand, no precise data required
- **Disadvantages**: Subjective, less precise, hard to compare different assessments
- **Use When**: Quick assessment needed, limited data, non-financial risks
- **Example**: 5×5 risk matrix (Likelihood 1-5 × Impact 1-5 = Risk Score 1-25)

**2. Quantitative Risk Assessment**
- **Method**: Use numerical values (€, probabilities, ALE)
- **Advantages**: Precise, objective, supports cost-benefit analysis
- **Disadvantages**: Requires accurate data, time-consuming, complex
- **Use When**: Financial decisions, precise comparisons needed, data available
- **Example**: ALE (Annual Loss Expectancy) = ARO (Annual Rate of Occurrence) × SLE (Single Loss Expectancy)

**3. Hybrid Approach**
- **Method**: Combine qualitative and quantitative
- **Advantages**: Balances speed and precision
- **Use When**: Initial qualitative, then quantitative for high risks
- **Example**: Qualitative matrix, then quantitative cost-benefit for risks exceeding threshold

##### b) Impact Assessment (Detailed)

**Qualitative Impact Assessment:**

**Financial Impact:**
- Direct losses (revenue loss, asset replacement)
- Indirect losses (productivity loss, overtime costs)
- Fines and penalties (GDPR, NIS2, sector-specific)
- Legal costs (lawsuits, settlements)
- Recovery costs (forensics, remediation, PR)

**Reputational Impact:**
- Customer trust loss (churn rate increase)
- Brand damage (negative media coverage)
- Stakeholder confidence loss (investor, partner)
- Market share erosion (competitive disadvantage)
- Recruitment impact (talent attraction difficulty)

**Operational Impact:**
- Business process disruption (hours/days of downtime)
- Productivity loss (employee time, workarounds)
- Service degradation (SLA breaches, customer complaints)
- Safety impact (physical harm risk)
- Environmental impact (pollution, waste)

**Legal/Regulatory Impact:**
- Non-compliance (GDPR, NIS2, ISO 27001, industry regulations)
- License revocation (loss of certification, authorization)
- Legal action (lawsuits, criminal prosecution)
- Contractual breach (client penalties, contract termination)
- Regulatory scrutiny (audits, increased oversight)

**Strategic Impact:**
- Competitive disadvantage (loss of IP, trade secrets)
- Market opportunity loss (unable to enter new markets)
- Strategic initiative failure (M&A, digital transformation)
- Partner/supplier relationships damaged
- Long-term viability threatened

**Quantitative Impact Assessment:**

**Single Loss Expectancy (SLE):**
```
SLE = Asset Value × Exposure Factor

Example:
- Asset: Web Server
- Asset Value: €100,000 (replacement + data + configuration)
- Exposure Factor: 60% (ransomware would affect 60% of value)
- SLE = €100,000 × 0.60 = €60,000
```

**Total Impact Calculation:**
```
Total Financial Impact = Direct Loss + Indirect Loss + Fines + Legal + Recovery

Example (Data Breach):
- Direct Loss: €50,000 (forensics, notification)
- Indirect Loss: €100,000 (customer churn, reputation)
- GDPR Fine: €200,000 (4% annual revenue or €20M, whichever lower)
- Legal Costs: €80,000 (lawsuits from affected individuals)
- Recovery: €70,000 (credit monitoring for victims, PR campaign)
Total: €500,000
```

##### c) Likelihood Assessment (Detailed)

**Qualitative Likelihood Assessment:**

**Likelihood Scale (5 levels):**

| Level | Label | Description | Frequency | Probability |
|-------|-------|-------------|-----------|-------------|
| **1** | **Rare** | Exceptional circumstances only | < 1 in 10 years | < 10% |
| **2** | **Unlikely** | Could occur but not expected | 1 in 5-10 years | 10-30% |
| **3** | **Possible** | Might occur at some time | 1 in 2-5 years | 30-50% |
| **4** | **Likely** | Will probably occur | 1-2 per year | 50-80% |
| **5** | **Almost Certain** | Expected to occur in most circumstances | > 2 per year | > 80% |

**Factors Affecting Likelihood:**
1. **Threat Capability**: How skilled/resourced is the threat actor?
2. **Threat Motivation**: How motivated is the threat to exploit?
3. **Vulnerability Severity**: How easy is it to exploit?
4. **Control Effectiveness**: How well do existing controls mitigate?
5. **Historical Data**: How often has this occurred before?
6. **Threat Intelligence**: What is the current threat landscape?
7. **Environmental Factors**: External conditions (geopolitical, economic)

**Likelihood Assessment Example:**
```
Risk: Ransomware Attack on File Server

Threat Capability: High (ransomware-as-a-service widely available)
Threat Motivation: High (financial gain, opportunistic targeting)
Vulnerability: Medium (some security measures, but phishing susceptible)
Existing Controls:
- Antivirus: 70% effective
- Email filtering: 60% effective
- User awareness: 50% effective
- Backup: 90% effective (reduces impact, not likelihood)

Historical Data: 2 similar incidents in past 3 years → 0.67/year
Industry Data: 37% of organizations experienced ransomware in past year
Threat Intelligence: Ransomware campaigns targeting healthcare sector (high)

Estimated Likelihood: 4 (Likely) - 60% probability in next 12 months
```

**Quantitative Likelihood Assessment:**

**Annual Rate of Occurrence (ARO):**
```
ARO = Number of incidents per year

Example (Based on Historical Data):
- 3 phishing incidents in past 2 years
- ARO = 3 / 2 = 1.5 incidents per year

Example (Based on Probability):
- Probability of SQL injection attack: 40% per year
- ARO = 0.40
```

**Calculating Probability:**
```
Probability = Threat Frequency × Vulnerability Exploitability × (1 - Control Effectiveness)

Example:
- Threat Frequency: 10 phishing emails per month = 120/year
- Vulnerability (user click rate): 5% = 0.05
- Control (email filtering): 80% effective = 0.20 passthrough
- Probability = 120 × 0.05 × 0.20 = 1.2 successful phishing per year
```

##### d) Risk Level Determination

**Inherent Risk Calculation:**
```
Inherent Risk = Impact × Likelihood (before controls)

Example:
- Impact: 4 (Major)
- Likelihood: 4 (Likely)
- Inherent Risk: 16 (Critical)
```

**Residual Risk Calculation:**
```
Residual Risk = Impact × (Likelihood × (1 - Control Effectiveness))

Example:
- Inherent Likelihood: 4
- Control Effectiveness: 75% (average of all applicable controls)
- Residual Likelihood: 4 × (1 - 0.75) = 1 (Rare)
- Residual Risk: 4 × 1 = 4 (Low)
```

**Annual Loss Expectancy (ALE) - Quantitative:**
```
ALE = SLE × ARO

Example:
- SLE: €60,000 (single incident loss)
- ARO: 0.4 (40% probability per year)
- ALE: €60,000 × 0.4 = €24,000 per year
```

#### 6.3.3 Risk Evaluation

**Purpose:** Compare estimated risk levels against risk criteria to determine significance

##### a) Comparing Risk Against Criteria

**Risk Appetite Comparison:**
```
Risk Score: 16 (Critical)
Risk Appetite for Category: 12 (High)

Result: 16 > 12 → Risk EXCEEDS appetite → Treatment Required
```

**Decision Matrix:**

| Risk Level | Risk Score | Decision | Action Required |
|------------|------------|----------|-----------------|
| **Low** | 1-3 | Accept | Monitor, no treatment needed |
| **Medium** | 4-9 | Accept or Reduce | Evaluate cost-benefit, may treat |
| **High** | 10-15 | Reduce or Transfer | Treatment required, management approval |
| **Critical** | 16-25 | Reduce Immediately | Urgent treatment, senior management/board |

##### b) Prioritizing Risks

**Prioritization Factors:**
1. **Risk Level**: Higher risk = higher priority
2. **Risk Appetite Exceedance**: How much does risk exceed threshold?
3. **Trend**: Is risk increasing or decreasing?
4. **Treatment Feasibility**: Can risk be treated effectively?
5. **Treatment Cost**: Is treatment cost-effective?
6. **Legal/Regulatory**: Compliance requirements mandate treatment?
7. **Business Criticality**: Does risk affect critical business process?

**Priority Scoring Example:**
```
Risk A: Score 20, Exceeds appetite by 8, Increasing trend, Critical process
Priority: 5 (Critical) - Treat immediately

Risk B: Score 12, Exceeds appetite by 3, Stable trend, Non-critical process
Priority: 3 (Medium) - Treat within 6 months

Risk C: Score 6, Below appetite, Decreasing trend
Priority: 1 (Low) - Monitor only
```

---

### 6.4 Risk Treatment

**Purpose:** Select and implement options for addressing risk

**Four Risk Treatment Strategies (ISO 31000 terminology):**
1. **Risk Modification** (ISO 27005: "Risk Reduction" / "Risk Mitigation")
2. **Risk Retention** (ISO 27005: "Risk Acceptance")
3. **Risk Avoidance**
4. **Risk Sharing** (ISO 27005: "Risk Transfer")

#### 6.4.1 Risk Treatment Option Selection

##### Option 1: Risk Modification (Reduce)

**Method:** Implement controls to reduce likelihood and/or impact

**When to Use:**
- Risk exceeds appetite
- Treatment is cost-effective
- Controls available and feasible

**How to Reduce Likelihood:**
- Preventive controls (e.g., firewalls, access controls, patches)
- Detective controls (e.g., IDS, logging, monitoring)
- User awareness training

**How to Reduce Impact:**
- Backup and recovery procedures
- Redundancy (hot spare, failover)
- Business continuity plans
- Data encryption (limits confidentiality breach impact)
- Network segmentation (limits breach spread)

**Example:**
```
Risk: Ransomware Attack
Inherent Risk: 20 (Impact 5 × Likelihood 4)

Treatment:
1. Implement MFA (reduces likelihood) - 70% effective
2. Deploy EDR (reduces likelihood) - 80% effective
3. Implement immutable backups (reduces impact from 5 to 2) - 90% effective

Residual Likelihood: 4 × (1 - 0.75 combined) = 1 (Rare)
Residual Impact: 2 (Minor) - can recover from backup
Residual Risk: 2 × 1 = 2 (Low) ✓ Within appetite
```

##### Option 2: Risk Retention (Accept)

**Method:** Acknowledge risk and decide not to treat (or accept residual risk after treatment)

**When to Use:**
- Risk within appetite
- Treatment cost exceeds potential loss
- No feasible treatment available
- Risk is low priority

**Requirements (ISO 27005):**
1. **Explicit Decision**: Formal acceptance, not passive neglect
2. **Documented Justification**: Why is acceptance appropriate?
3. **Approval**: Obtained from appropriate authority level
4. **Conditions**: Any conditions for continued acceptance?
5. **Review Date**: When to re-evaluate?

**Example:**
```
Risk: Legacy System Vulnerability
Residual Risk: 6 (Medium)
Risk Appetite: 9 (Medium)

Decision: Accept
Justification:
- Residual risk (6) below appetite threshold (9)
- Treatment (system upgrade) costs €80,000
- Estimated annual loss: €10,000
- System scheduled for decommission in 18 months
- Compensating controls in place (network segmentation, monitoring)

Conditions:
- Monthly vulnerability scans
- Quarterly penetration testing
- Immediate escalation if exploit detected

Approved By: IT Manager (Level 1 authority for residual risk 6)
Review Date: 6 months or upon threat landscape change
```

##### Option 3: Risk Avoidance

**Method:** Eliminate activity that gives rise to risk

**When to Use:**
- Risk cannot be reduced to acceptable level
- Treatment cost too high
- Activity not critical to business
- Legal/regulatory prohibits activity

**Example:**
```
Risk: Data Breach from Storing Credit Cards
Inherent Risk: 25 (Critical)

Treatment: Avoid
Decision: Stop storing credit card numbers
- Use payment gateway (PCI DSS compliant third party)
- Only store tokenized references
- Eliminates PCI DSS Scope
- Risk eliminated entirely (score reduces to 0)

Trade-off: Slightly increased transaction fees, but eliminates compliance burden
```

##### Option 4: Risk Sharing (Transfer)

**Method:** Share risk with another party (insurance, outsourcing, contracts)

**When to Use:**
- Risk too high for organization to bear alone
- Third party better equipped to manage risk
- Cost-effective to transfer

**Transfer Mechanisms:**
1. **Insurance**: Cyber insurance, business interruption insurance
2. **Outsourcing**: Cloud provider, managed security service provider (MSSP)
3. **Contracts**: Hold harmless clauses, indemnification, SLAs with penalties

**Important:** Risk transfer does NOT eliminate risk, only shares financial/operational burden. Organization still responsible under ISO 27001/GDPR!

**Example:**
```
Risk: Major Cyber Attack
Inherent Risk: 20 (Critical)

Treatment: Reduce + Transfer
1. Implement controls (firewalls, IDS, training) → Residual risk: 12 (High)
2. Purchase cyber insurance (€5M coverage) → Transfers financial impact
3. Contract with incident response retainer → Transfers response burden

Result:
- Residual financial risk: €12,000 (policy deductible)
- Operational risk remains: 12 (High) - still responsible for prevention
- Reputational risk remains: Cannot insure reputation
```

#### 6.4.2 Risk Treatment Plan

**Purpose:** Document how selected treatment options will be implemented

**Treatment Plan Must Include:**

1. **Treatment Strategy**: Reduce, Accept, Avoid, Transfer
2. **Proposed Actions**: Specific steps to implement
3. **Resources Required**: Personnel, budget, technology
4. **Responsibilities**: Who is accountable for each action?
5. **Timeline**: Start date, milestones, target completion
6. **Expected Outcome**: Target residual risk level
7. **Performance Indicators**: How to measure success?
8. **Approval**: Who approved the plan?

**Example Treatment Plan:**
```
Risk ID: R-2024-042
Risk Name: SQL Injection Vulnerability in Customer Portal
Current Risk Level: 20 (Critical)
Target Risk Level: 4 (Low)

Strategy: Reduce (Risk Modification)

Actions:
1. Immediate (Week 1-2): Deploy Web Application Firewall (WAF)
   - Responsible: IT Security Team
   - Cost: €5,000 (annual subscription)
   - Expected Reduction: Likelihood 4 → 2

2. Short-term (Week 3-8): Code Review and Remediation
   - Responsible: Development Team
   - Cost: €15,000 (developer time)
   - Expected Reduction: Likelihood 2 → 1
   - Verification: Penetration test after completion

3. Long-term (Month 3-6): Implement Secure SDLC
   - Responsible: CTO
   - Cost: €30,000 (tools, training, process)
   - Expected Reduction: Prevents future vulnerabilities
   - ISO 27001 Controls: A.8.25, A.14.2.1

Resources:
- Budget: €50,000 (approved)
- Personnel: 2 developers (200 hours), 1 security analyst (80 hours)
- External: Penetration testing firm (€5,000)

Timeline:
- Start: 2024-11-25
- Milestone 1: WAF deployed (2024-12-09)
- Milestone 2: Code fixes completed (2025-01-20)
- Milestone 3: Pen test passed (2025-02-03)
- Completion: SDLC implemented (2025-05-01)

Expected Outcome:
- Residual Risk: 4 (Impact 4 × Likelihood 1)
- Risk Level: Low (within appetite threshold 9)

Performance Indicators:
- WAF blocking rate: > 95% of attacks
- Penetration test: Zero high/critical findings
- Code review: Zero SQL injection vulnerabilities

Approved By: CTO (2024-11-20)
Status: In Progress
```

#### 6.4.3 Residual Risk Assessment

**Purpose:** Determine risk remaining after treatment implementation

**Calculate Residual Risk:**
```
Residual Risk = Inherent Risk - Risk Reduction (from controls)

Using Risk Matrix:
Residual Impact = Inherent Impact (if controls don't reduce impact)
                  OR Lower Impact (if controls reduce consequences)

Residual Likelihood = Inherent Likelihood × (1 - Control Effectiveness)

Residual Risk Score = Residual Impact × Residual Likelihood
```

**Example:**
```
Inherent Risk:
- Impact: 5 (Critical) - €500k data breach
- Likelihood: 4 (Likely) - 60% probability
- Score: 20 (Critical)

Controls Implemented:
- MFA: 80% effective (reduces likelihood)
- Encryption: Reduces impact from 5 to 2 (data unreadable if breached)
- DLP: 70% effective (reduces likelihood)

Residual Risk Calculation:
- Residual Impact: 2 (Minor) - encrypted data has lower impact
- Combined Control Effectiveness: 1 - ((1-0.80) × (1-0.70)) = 94%
- Residual Likelihood: 4 × (1 - 0.94) = 0.24 → 1 (Rare)
- Residual Risk Score: 2 × 1 = 2 (Low) ✓
```

**Verify Residual Risk:**
- Is residual risk within appetite?
- Have all planned controls been implemented?
- Are controls operating effectively? (test them!)
- Are there any new vulnerabilities introduced by controls?
- Is monitoring in place to detect control failures?

#### 6.4.4 Risk Acceptance

**Purpose:** Obtain formal approval to accept residual risk

**When Risk Acceptance Required:**
- Residual risk remains after treatment (always some residual risk)
- Decision to accept risk without treatment
- Residual risk exceeds appetite but cannot be further reduced

**Acceptance Requirements:**

1. **Explicit Approval**: Written approval from authorized person
2. **Documented Justification**: Why is residual risk acceptable?
3. **Appropriate Authority Level**: Based on risk level
   - Low risk (1-3): Manager level
   - Medium risk (4-9): Senior management (Director, VP)
   - High risk (10-15): Executive (C-level)
   - Critical risk (16-25): Board/Executive Committee

4. **Conditions**: Any conditions for acceptance (monitoring, review triggers)
5. **Review Period**: When to re-evaluate (minimum annually, or sooner if context changes)
6. **Expiry Date**: Acceptance valid until when?

**Risk Acceptance Statement Template:**
```
Risk Acceptance Statement

Risk ID: R-2024-042
Risk Name: SQL Injection Vulnerability in Customer Portal
Residual Risk Level: 4 (Low)
Risk Category: Technical
Risk Appetite for Category: 9 (Medium)

Decision: ACCEPT RESIDUAL RISK

Justification:
Following implementation of WAF, code remediation, and secure SDLC, residual risk
has been reduced from 20 (Critical) to 4 (Low). This is well below risk appetite
threshold of 9 (Medium) for technical risks. Residual risk (4% probability of
minor breach causing €10k impact) is acceptable given treatment costs (€50k invested).

Conditions for Acceptance:
1. WAF must maintain > 95% blocking rate (monitored monthly)
2. Quarterly penetration testing with zero high/critical findings
3. Annual code security audit
4. Immediate escalation if new vulnerability discovered
5. Re-assess if customer data volume increases > 2x current level

Review Triggers:
- Annual review date: 2025-11-20
- Immediate review if:
  - New SQL injection exploit techniques emerge
  - Penetration test identifies high/critical finding
  - Actual security incident occurs
  - Application architecture changes significantly

Approved By: Jane Smith, CTO
Approval Date: 2024-11-20
Expiry Date: 2025-11-20 (12 months)
Next Review: 2025-11-20 or upon trigger event

Signature: ____________________
```

**Common Mistakes in Risk Acceptance:**
❌ Passive acceptance (no formal decision)
❌ Accepting inherent risk instead of residual risk
❌ No documented justification
❌ Approval from wrong authority level
❌ No expiry/review date
❌ No conditions or monitoring
❌ Accepting risk that exceeds appetite without escalation

**Best Practices:**
✅ Always document acceptance formally
✅ Accept residual risk (after treatment), not inherent risk
✅ Include clear justification with cost-benefit analysis
✅ Obtain approval from appropriate level based on risk score
✅ Set expiry date (max 1-2 years)
✅ Define specific conditions and monitoring requirements
✅ Escalate to higher authority if risk exceeds appetite

---

### 6.5 Risk Communication and Consultation

**Purpose:** Share risk information with stakeholders throughout the process

**Key Principles:**
- **Continuous**: Not one-time, but ongoing throughout risk management process
- **Two-way**: Both inform stakeholders AND gather their input
- **Tailored**: Adapt message to audience (technical for IT, business-focused for management)
- **Timely**: Communicate at right time in decision-making process

**Communication Activities:**

**During Context Establishment (6.2):**
- Inform stakeholders of risk assessment scope
- Gather input on risk criteria and appetite
- Clarify roles and responsibilities

**During Risk Assessment (6.3):**
- Interview asset owners about impact
- Consult threat intelligence sources
- Validate risk levels with business process owners

**During Risk Treatment (6.4):**
- Present treatment options to decision-makers
- Obtain budget approval for treatment
- Coordinate treatment implementation with IT/business teams

**During Monitoring (6.6):**
- Report risk metrics to management
- Alert on risks exceeding thresholds
- Share lessons learned from incidents

**Stakeholder Communication Matrix:**

| Stakeholder | Information Need | Frequency | Format |
|-------------|------------------|-----------|--------|
| **Board/Executives** | High-level risk overview, risks exceeding appetite, critical incidents | Quarterly | Executive summary, dashboard |
| **Senior Management** | Risk register, treatment status, budget needs, compliance status | Monthly | Risk register, status reports |
| **Risk Owners** | Detailed risk info, treatment plans, monitoring results, action items | Weekly/Monthly | Detailed reports, emails |
| **IT/Security Team** | Technical vulnerabilities, threats, incidents, control effectiveness | Daily/Weekly | Technical reports, tickets, alerts |
| **Employees** | Security awareness, policies, incident reporting procedures | Quarterly | Training, newsletters, posters |
| **Auditors/Regulators** | Compliance evidence, risk assessments, treatment plans, incidents | Annually/Ad-hoc | Audit reports, documentation |

---

### 6.6 Risk Monitoring and Review

**Purpose:** Ensure risk management remains effective over time

**Monitoring Activities:**

**1. Monitor Risk Treatment Implementation**
- Track treatment plan progress against milestones
- Identify and resolve roadblocks
- Verify controls implemented as planned
- Test control effectiveness

**2. Monitor Risk Changes**
- New threats or vulnerabilities identified
- Changes in likelihood (e.g., incidents occurred)
- Changes in impact (e.g., asset value increased)
- Changes in context (e.g., new regulation)

**3. Monitor Control Effectiveness**
- Control performance metrics (e.g., firewall blocking rate)
- Control failures or weaknesses identified
- Control testing results (penetration tests, audits)

**4. Monitor Accepted Risks**
- Review accepted risks approaching expiry
- Check if conditions for acceptance still valid
- Verify risk level hasn't increased

**5. Monitor External Environment**
- Threat intelligence feeds
- Vulnerability databases (CVE, NVD)
- Regulatory changes
- Industry trends

**Review Activities:**

**Regular Risk Reviews:**
- **Continuous**: Monitoring of KRIs, logs, alerts
- **Monthly**: Risk register review, new risks identified
- **Quarterly**: Risk report to management, treatment progress
- **Annually**: Complete risk assessment refresh, appetite review

**Trigger-Based Reviews:**
- **After Incidents**: Validate/update related risk assessments
- **After Significant Changes**: New systems, M&A, reorganization, new regulations
- **After Control Changes**: New controls implemented, controls decommissioned
- **After Audits**: Audit findings may identify new risks

**Key Risk Indicators (KRIs):**
- Number of high/critical risks
- Percentage of risks exceeding appetite
- Percentage of overdue treatment plans
- Number of incidents by category
- Control effectiveness scores
- Time to treat risks (average days)
- Trend of risk levels (increasing/decreasing)

**Risk Review Checklist:**
```
Risk Review Checklist (Annual)

Risk Context:
☐ Has risk appetite changed?
☐ Are risk criteria still appropriate?
☐ Has scope changed (new systems, decommissioned assets)?
☐ Have legal/regulatory requirements changed?

Risk Assessment:
☐ Have new threats emerged?
☐ Have new vulnerabilities been identified?
☐ Have asset values changed?
☐ Have incidents occurred affecting likelihood?

Risk Treatment:
☐ Are treatment plans on schedule?
☐ Are implemented controls effective?
☐ Are there overdue treatment actions?
☐ Is residual risk still acceptable?

Risk Acceptance:
☐ Are accepted risks still within appetite?
☐ Are acceptance conditions still met?
☐ Are any acceptances expiring?
☐ Do accepted risks need re-approval?

Continual Improvement:
☐ What worked well this year?
☐ What improvements are needed?
☐ Are there lessons learned from incidents?
☐ Should methodology be updated?
```

---

## Key Definitions (ISO 27005)

**Asset**: Anything that has value to the organization
- Examples: Information, software, hardware, services, people, reputation

**Threat**: Potential cause of an unwanted incident
- Examples: Hacker, malware, fire, human error, hardware failure

**Vulnerability**: Weakness that can be exploited by a threat
- Examples: Unpatched software, weak password, unlocked door

**Risk**: Effect of uncertainty on objectives (combination of likelihood and impact)
- Formula: Risk = Threat × Vulnerability × Impact

**Control**: Measure that modifies risk
- Examples: Firewall, encryption, access control, backup, policy, training

**Inherent Risk**: Risk before controls applied (gross risk)

**Residual Risk**: Risk remaining after controls applied (net risk)

**Risk Appetite**: Amount of risk an organization is willing to accept

**Risk Tolerance**: Acceptable deviation around risk appetite

**Risk Treatment**: Process to modify risk (reduce, accept, avoid, transfer)

**Risk Owner**: Person accountable for managing a specific risk

**Asset Owner**: Person responsible for an information asset

---

## ISO 27005 Compliance Checklist

**6.2 Context Establishment**
- ☐ Risk management scope defined and documented
- ☐ Risk criteria established (impact scales, likelihood scales)
- ☐ Risk evaluation criteria defined (risk appetite, thresholds)
- ☐ Roles and responsibilities assigned
- ☐ External and internal context analyzed

**6.3.1 Risk Identification**
- ☐ Asset inventory complete with CIA ratings
- ☐ Threats identified for each asset
- ☐ Vulnerabilities identified for each asset
- ☐ Existing controls documented
- ☐ Consequences (impacts) determined

**6.3.2 Risk Analysis**
- ☐ Risk assessment methodology selected (qualitative/quantitative/hybrid)
- ☐ Impact assessed for each risk
- ☐ Likelihood assessed for each risk
- ☐ Inherent risk calculated

**6.3.3 Risk Evaluation**
- ☐ Risks compared against risk criteria
- ☐ Risks prioritized
- ☐ Risks exceeding appetite identified

**6.4.1 Risk Treatment Option Selection**
- ☐ Treatment strategy selected for each risk (reduce/accept/avoid/transfer)
- ☐ Cost-benefit analysis performed

**6.4.2 Risk Treatment Plan**
- ☐ Treatment plans documented with actions, timelines, owners, resources
- ☐ Controls selected (preferably from ISO 27001 Annex A)
- ☐ Treatment plans approved by management

**6.4.3 Residual Risk Assessment**
- ☐ Residual risk calculated after treatment
- ☐ Residual risk compared against appetite

**6.4.4 Risk Acceptance**
- ☐ Residual risks formally accepted
- ☐ Acceptance approved by appropriate authority level
- ☐ Acceptance documented with justification, conditions, review date

**6.5 Risk Communication and Consultation**
- ☐ Stakeholders identified
- ☐ Communication plan established
- ☐ Regular risk reports to management

**6.6 Risk Monitoring and Review**
- ☐ KRIs defined and monitored
- ☐ Treatment progress tracked
- ☐ Risks reviewed regularly (minimum annually)
- ☐ Risk assessment updated after significant changes or incidents

---

## Common Mistakes in ISO 27005 Implementation

**Mistake 1: Skipping Context Establishment**
- **Problem**: Start risk assessment without defining scope, criteria, appetite
- **Impact**: Inconsistent assessments, no clear acceptance criteria
- **Solution**: Complete Clause 6.2 first - define scope, matrix, appetite

**Mistake 2: Asset Inventory Incomplete**
- **Problem**: Missing critical assets (cloud services, SaaS, personal devices)
- **Impact**: Risks unidentified, gaps in coverage
- **Solution**: Systematic asset discovery, include all asset types (Annex B)

**Mistake 3: Threat/Vulnerability Identification Too Generic**
- **Problem**: "Cyber attack" instead of specific threats like "Phishing", "SQL Injection"
- **Impact**: Cannot assess likelihood accurately, treatment too vague
- **Solution**: Use specific threat/vulnerability catalogs (Annex C, D)

**Mistake 4: No Data for Likelihood Assessment**
- **Problem**: Guessing likelihood without historical data, incident data, threat intelligence
- **Impact**: Inaccurate risk scores, wrong prioritization
- **Solution**: Collect incident history, use threat intelligence, industry benchmarks

**Mistake 5: Ignoring Existing Controls**
- **Problem**: Assess inherent risk but don't identify what controls are already in place
- **Impact**: Cannot calculate residual risk, may duplicate controls
- **Solution**: Document existing controls (ISO 27001 Annex A mapping)

**Mistake 6: Treatment Plan Without Details**
- **Problem**: "Implement better security" instead of specific actions
- **Impact**: Cannot execute, track, or verify treatment
- **Solution**: Specific actions, owners, timelines, resources, verification method

**Mistake 7: No Formal Risk Acceptance**
- **Problem**: Risks left in "assessed" state, no explicit acceptance decision
- **Impact**: Non-compliance with ISO 27001 Clause 6.1.3(e)
- **Solution**: Formal acceptance statement, appropriate approval level

**Mistake 8: Risk Assessment "One and Done"**
- **Problem**: Perform initial risk assessment, never update
- **Impact**: Outdated risk profile, new threats/vulnerabilities missed
- **Solution**: Annual reviews minimum, trigger-based reviews (incidents, changes)

**Mistake 9: No Link to ISO 27001 Controls**
- **Problem**: Assess risks separately from ISMS control implementation
- **Impact**: Duplication, inconsistency, compliance gaps
- **Solution**: Map risks to ISO 27001 Annex A controls, use SoA

**Mistake 10: Communication Only After Assessment**
- **Problem**: No stakeholder consultation during assessment
- **Impact**: Inaccurate impact assessment, no business buy-in
- **Solution**: Consult stakeholders throughout (Clause 6.5)

---

## Integration with Other Standards

### ISO 27001:2022 Integration

**ISO 27001 Clauses Requiring ISO 27005:**

**Clause 6.1.2 - Information Security Risk Assessment:**
- Organization SHALL define and apply risk assessment process
- ISO 27005 Clause 6.3 provides detailed methodology

**Clause 6.1.3 - Information Security Risk Treatment:**
- Organization SHALL define and apply risk treatment process
- ISO 27005 Clause 6.4 provides detailed guidance

**Clause 8.2 - Conduct Risk Assessments:**
- Risk assessments SHALL be performed at planned intervals
- ISO 27005 Clause 6.6 provides monitoring and review guidance

**Clause 8.3 - Risk Treatment:**
- Implement risk treatment plan
- ISO 27005 Clause 6.4.2 provides treatment plan structure

**Annex A Controls:**
- ISO 27005 helps select relevant Annex A controls based on risk assessment
- Control effectiveness reduces residual risk

### ISO 31000:2018 Integration

ISO 27005:2022 aligns with ISO 31000 risk management framework:

**ISO 31000 Principles → ISO 27005 Implementation:**
1. Integrated → Embedded in ISMS (ISO 27001)
2. Structured and Comprehensive → Clause 6 process
3. Customized → Context establishment (6.2)
4. Inclusive → Communication and consultation (6.5)
5. Dynamic → Monitoring and review (6.6)
6. Best Available Information → Evidence-based assessment
7. Human and Cultural Factors → Stakeholder consultation
8. Continual Improvement → Lessons learned, updates

**ISO 31000 Process → ISO 27005 Mapping:**
- ISO 31000 Clause 6.4 (Risk Assessment) → ISO 27005 Clause 6.3
- ISO 31000 Clause 6.5 (Risk Treatment) → ISO 27005 Clause 6.4
- ISO 31000 Clause 6.3 (Communication) → ISO 27005 Clause 6.5
- ISO 31000 Clause 6.6 (Monitoring) → ISO 27005 Clause 6.6

### ISO 22301:2019 (BCM) Integration

ISO 27005 complements ISO 22301 for business continuity:

**Business Impact Analysis (BIA) ↔ Risk Assessment:**
- BIA identifies critical processes and recovery objectives (RTO, RPO, MTPD)
- Risk assessment identifies threats to those processes
- Combined: Prioritize risks based on business criticality

**Risk Treatment ↔ BC Strategy:**
- Risk treatment may include BC plans as controls
- BC strategy addresses availability risks specifically

**Example:**
```
Asset: Payroll System (critical process)
BIA Result: RTO = 4 hours, MTPD = 24 hours

Risk Assessment:
- Risk: Payroll system failure
- Impact: 5 (Critical) - cannot pay employees, legal obligation
- Likelihood: 3 (Possible)
- Inherent Risk: 15 (High)

Risk Treatment (includes BCM):
- Implement system redundancy (reduces likelihood)
- Implement BC plan with manual payroll procedure (reduces impact)
- Residual Risk: 6 (Medium) - can pay within RTO using BC plan
```

---

## ISO 27005 Quick Reference Table

| Clause | Activity | Key Outputs | When |
|--------|----------|-------------|------|
| **6.2** | Context Establishment | Scope, criteria, risk matrix, risk appetite | Initial setup, annual review |
| **6.3.1** | Risk Identification | Asset inventory, threat list, vulnerability list, risk scenarios | Initial, then continuous |
| **6.3.2** | Risk Analysis | Inherent risk scores (impact × likelihood) | Per risk identified |
| **6.3.3** | Risk Evaluation | Risk prioritization, list of risks exceeding appetite | After analysis |
| **6.4.1** | Treatment Option Selection | Treatment strategy per risk (reduce/accept/avoid/transfer) | For risks exceeding appetite |
| **6.4.2** | Treatment Planning | Treatment plans with actions, timelines, owners, budget | For risks being treated |
| **6.4.3** | Residual Risk Assessment | Residual risk scores (after controls) | After treatment implementation |
| **6.4.4** | Risk Acceptance | Signed risk acceptance statements | For all residual risks |
| **6.5** | Communication | Stakeholder updates, management reports | Continuous |
| **6.6** | Monitoring & Review | KRIs, risk register updates, lessons learned | Continuous, quarterly, annually |

---

## Useful Resources

**ISO Standards:**
- ISO/IEC 27005:2022 (this standard)
- ISO/IEC 27001:2022 (ISMS requirements)
- ISO/IEC 27000:2018 (Vocabulary)
- ISO 31000:2018 (Risk management guidelines)

**Supporting Standards:**
- ISO/IEC 27002:2022 (Information security controls reference)
- ISO/IEC 27701:2019 (Privacy information management)
- ISO 22301:2019 (Business continuity management)

**Threat/Vulnerability Databases:**
- CVE (Common Vulnerabilities and Exposures): https://cve.mitre.org
- NVD (National Vulnerability Database): https://nvd.nist.gov
- OWASP Top 10: https://owasp.org/Top10

**Risk Assessment Tools:**
- NIST SP 800-30 (Risk Assessment Guide)
- OCTAVE (Operationally Critical Threat, Asset, and Vulnerability Evaluation)
- FAIR (Factor Analysis of Information Risk)

---

## Summary

ISO 27005:2022 provides comprehensive guidance for information security risk management:

✅ **Context Establishment** (6.2) - Define scope, criteria, appetite before starting
✅ **Risk Identification** (6.3.1) - Systematically identify assets, threats, vulnerabilities
✅ **Risk Analysis** (6.3.2) - Assess impact and likelihood (qualitative/quantitative)
✅ **Risk Evaluation** (6.3.3) - Compare against criteria, prioritize
✅ **Risk Treatment** (6.4) - Select strategy (reduce/accept/avoid/transfer), plan, implement
✅ **Residual Risk Assessment** (6.4.3) - Calculate risk after controls
✅ **Risk Acceptance** (6.4.4) - Formal approval at appropriate level
✅ **Communication** (6.5) - Stakeholder engagement throughout
✅ **Monitoring & Review** (6.6) - Continuous improvement, regular updates

ISO 27005 supports ISO 27001 compliance by providing the detailed methodology for Clauses 6.1.2 (Risk Assessment) and 6.1.3 (Risk Treatment).

**Key Success Factors:**
1. Obtain management support and resources
2. Define clear risk criteria and appetite upfront
3. Use structured methodology (don't improvise)
4. Base assessments on data (incidents, threats, vulnerabilities)
5. Link to ISO 27001 Annex A controls
6. Communicate with stakeholders throughout
7. Document everything (especially risk acceptance!)
8. Review and update regularly (minimum annually)

This guidance, combined with practical application experience, enables effective information security risk management aligned with ISO 27001 requirements. 🎯