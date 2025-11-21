# DORA ITS - Incident Reporting Templates

**Commission Implementing Regulation (EU) 2024/1502**

## Official Information

- **Full Title**: Commission Implementing Regulation (EU) 2024/1502 of 29 May 2024 laying down implementing technical standards for the application of Regulation (EU) 2022/2554 with regard to standard templates for the initial notification, intermediate and final reports on major ICT-related incidents
- **Adopted**: May 29, 2024
- **Published**: Official Journal L 1502, June 3, 2024
- **Application Date**: January 17, 2025
- **Legal Basis**: DORA Article 20(1) (Incident reporting templates)
- **Official Text**: https://eur-lex.europa.eu/eli/reg_impl/2024/1502/oj

## Scope

Applies to all financial entities required to report major ICT-related incidents under DORA Article 20.

Provides:
1. Standard template for **initial notification** (≤4 hours)
2. Standard template for **intermediate report**
3. Standard template for **final report** (≤1 month)

## Template Structure

### Common Fields (All Reports)

**Section A: Entity Information**
1. **Reporting Entity**
   - Legal name
   - LEI (Legal Entity Identifier) - if applicable
   - National identifier (e.g., BaFin ID, FCA number)
   - Registered address
   - Contact person (name, email, phone - 24/7)

2. **Entity Type**
   - Credit institution
   - Investment firm
   - Payment institution
   - Insurance undertaking
   - Central counterparty
   - Trading venue
   - Other (specify)

3. **Competent Authority**
   - Authority name (e.g., BaFin, ECB, AMF)
   - Reporting channel (portal, email, API)

**Section B: Incident Identification**
1. **Incident ID**
   - Unique identifier (entity-assigned)
   - Format: [EntityCode]-[Year]-[SequentialNumber]
   - Example: DEBANK123-2025-042

2. **Report Type**
   - [ ] Initial notification
   - [ ] Intermediate report
   - [ ] Final report

3. **Report Version**
   - Version number (1.0, 1.1, 2.0, etc.)
   - Date and time of this report (ISO 8601 format)

4. **Previous Report Reference**
   - Reference to initial notification (for intermediate/final reports)

## Initial Notification Template (≤4 hours)

### Section C: Incident Detection

1. **Detection Information**
   - Date and time of detection (ISO 8601: YYYY-MM-DDTHH:MM:SSZ)
   - Detection method:
     - [ ] Automated monitoring (SIEM, IDS/IPS)
     - [ ] Manual discovery (staff observation)
     - [ ] Client report
     - [ ] Third-party notification
     - [ ] Other (specify)

2. **Incident Start Time** (estimated if not precisely known)
   - Date and time (ISO 8601)
   - [ ] Precise / [ ] Estimated

### Section D: Preliminary Classification

1. **Incident Type** (select all that apply)
   - [ ] Cyberattack
     - [ ] DDoS
     - [ ] Malware (Ransomware, Trojan, etc.)
     - [ ] Phishing/Social Engineering
     - [ ] Unauthorized access
     - [ ] Data breach
     - [ ] Other (specify)
   - [ ] System failure
     - [ ] Hardware failure
     - [ ] Software bug
     - [ ] Network outage
     - [ ] Power failure
     - [ ] Other (specify)
   - [ ] Human error
   - [ ] Third-party provider incident
   - [ ] Natural disaster
   - [ ] Unknown (under investigation)

2. **Severity Assessment**
   - [ ] Major ICT-related incident (meets DORA criteria)
   - [ ] Potentially major (under assessment)

3. **Root Cause** (preliminary, if known)
   - Brief description (max 500 characters)
   - [ ] Under investigation

### Section E: Impact Assessment (Preliminary)

1. **Affected Systems/Services**
   - List of affected ICT systems
   - Affected business functions:
     - [ ] Payment processing
     - [ ] Trading/execution
     - [ ] Client access (online banking, portals)
     - [ ] Internal systems
     - [ ] Data processing
     - [ ] Other (specify)

2. **Geographical Scope**
   - Affected countries (ISO 3166 codes)
   - Cross-border impact: [ ] Yes / [ ] No

3. **Client Impact**
   - Estimated number of affected clients:
     - [ ] <1,000
     - [ ] 1,000 - 10,000
     - [ ] 10,000 - 100,000
     - [ ] 100,000 - 1,000,000
     - [ ] >1,000,000
   - Client types:
     - [ ] Retail
     - [ ] Corporate
     - [ ] Institutional
     - [ ] All

4. **Service Availability**
   - Critical functions unavailable: [ ] Yes / [ ] No
   - Estimated downtime:
     - [ ] <1 hour
     - [ ] 1-4 hours
     - [ ] 4-24 hours
     - [ ] >24 hours
     - [ ] Unknown

5. **Data Impact**
   - Data loss: [ ] Yes / [ ] No / [ ] Unknown
   - Data breach (unauthorized access): [ ] Yes / [ ] No / [ ] Under investigation
   - If yes, estimated records affected: ________
   - Data types:
     - [ ] Personal data (GDPR)
     - [ ] Financial transactions
     - [ ] Authentication credentials
     - [ ] Business confidential
     - [ ] Other (specify)

6. **Financial Impact** (preliminary estimate)
   - Direct losses: €_________ (or [ ] Unknown)
   - Potential losses: €_________ (or [ ] Unknown)

### Section F: Immediate Response Actions

1. **Containment Measures**
   - Actions taken (checkboxes + free text):
     - [ ] Systems isolated/disconnected
     - [ ] Services suspended
     - [ ] Credentials revoked/changed
     - [ ] Traffic rerouted
     - [ ] Backup systems activated
     - [ ] Other: _______________

2. **Business Continuity Activation**
   - BCP activated: [ ] Yes / [ ] No
   - Alternative processes in place: [ ] Yes / [ ] No / [ ] Partial

3. **Communication**
   - Clients informed: [ ] Yes / [ ] No / [ ] Partial
   - Staff informed: [ ] Yes / [ ] No / [ ] Partial
   - Third parties informed: [ ] Yes / [ ] No / [ ] N/A
   - Media statement: [ ] Yes / [ ] No / [ ] Planned

4. **Investigation**
   - Forensic analysis initiated: [ ] Yes / [ ] No / [ ] Planned
   - External support engaged:
     - [ ] Law enforcement
     - [ ] Cyber security firm
     - [ ] ICT third-party provider
     - [ ] Other: _______________

### Section G: Indicators of Compromise (IoC)

**Technical Indicators** (if cyberattack):
1. IP addresses involved: _______________
2. Domain names: _______________
3. Malware hashes (MD5, SHA256): _______________
4. Attack vectors: _______________
5. Vulnerable systems/software: _______________

**Threat Actor Indicators** (if known):
- Known APT group: [ ] Yes (specify: _____) / [ ] No / [ ] Unknown
- Ransom demand: [ ] Yes (amount: €_____) / [ ] No

### Section H: Expected Resolution

1. **Estimated Recovery Time**
   - Expected service restoration:
     - Date and time (ISO 8601)
     - Confidence level: [ ] High / [ ] Medium / [ ] Low

2. **Next Update**
   - Expected next intermediate report: Date and time

## Intermediate Report Template

**Includes all sections from Initial Notification PLUS:**

### Section I: Incident Evolution

1. **Status Update**
   - Current status:
     - [ ] Ongoing
     - [ ] Contained (not fully resolved)
     - [ ] Resolved (services restored)
     - [ ] Under monitoring

2. **Changes Since Last Report**
   - Updated affected systems/services
   - Updated client impact numbers (actual vs. estimated)
   - Updated geographical scope
   - Updated financial impact

3. **Additional Impact Discovered**
   - New affected systems
   - Additional data compromise
   - Extended downtime

### Section J: Root Cause Analysis (Updated)

1. **Confirmed Root Cause** (if determined)
   - Detailed description
   - Contributing factors (technical, procedural, human)

2. **Attack Timeline** (for cyberattacks)
   - Initial compromise: Date/time
   - Lateral movement: Date/time
   - Objective achieved: Date/time
   - Detection: Date/time
   - Containment: Date/time

3. **Vulnerabilities Exploited**
   - CVE numbers (if applicable)
   - Configuration weaknesses
   - Process gaps

### Section K: Response Measures (Updated)

1. **Technical Remediation**
   - Actions completed
   - Actions in progress
   - Actions planned

2. **Service Restoration**
   - Percentage of services restored: _____%
   - Remaining outages (with ETR)

3. **Third-Party Involvement**
   - ICT provider actions
   - Law enforcement involvement
   - Regulatory notifications (other authorities)

### Section L: Preliminary Lessons Learned

1. **What Worked Well**
   - Effective controls
   - Successful procedures

2. **Areas for Improvement**
   - Control gaps identified
   - Procedural weaknesses

## Final Report Template (≤1 month)

**Includes all sections from Intermediate Report PLUS:**

### Section M: Comprehensive Incident Analysis

1. **Complete Timeline**
   - Tabular format: Date/Time | Event | Actor/System
   - From initial compromise to full resolution

2. **Final Root Cause**
   - Definitive root cause statement
   - Detailed vulnerability analysis
   - Contributing factors breakdown

3. **Final Impact Assessment**
   - Total clients affected (actual)
   - Total downtime (hours:minutes)
   - Geographic scope (finalized)
   - Final financial impact:
     - Direct losses: €_________
     - Indirect losses (estimated): €_________
     - Remediation costs: €_________

4. **Data Breach Details** (if applicable)
   - Records compromised (exact number)
   - Data categories affected
   - GDPR notifications made: [ ] Yes / [ ] No
   - Data protection authority informed: [ ] Yes / [ ] No / [ ] N/A

### Section N: Response and Recovery

1. **Complete Response Actions**
   - All technical measures taken
   - All procedural actions

2. **Recovery Metrics**
   - Time to detection: ___ hours/days
   - Time to containment: ___ hours/days
   - Time to recovery: ___ hours/days
   - Total incident duration: ___ hours/days

3. **Business Continuity Effectiveness**
   - BCP performance assessment
   - Alternative procedures effectiveness
   - Gaps identified

### Section O: Third-Party Analysis

1. **ICT Provider Involvement**
   - Provider name and role
   - Provider's contribution to incident (if applicable)
   - Provider's response assessment
   - Contractual implications

2. **Sub-Contractor Impact** (if relevant)
   - Sub-contractor involvement
   - Service disruption from sub-contractor

3. **Future Third-Party Actions**
   - Contract reviews required
   - Provider changes contemplated
   - Enhanced oversight measures

### Section P: Preventive and Corrective Measures

1. **Immediate Actions Taken**
   - Emergency patches applied
   - Access controls enhanced
   - Monitoring improvements

2. **Short-Term Remediation** (≤3 months)
   - Specific vulnerabilities fixed
   - Process improvements
   - Staff training
   - Budget: €_________

3. **Long-Term Improvements** (3-12 months)
   - Strategic changes (architecture, vendor diversification)
   - Major investments (new systems, controls)
   - Policy updates
   - Budget: €_________

4. **Responsible Parties**
   - Action items assigned to (roles/names)
   - Completion deadlines

### Section Q: Lessons Learned

1. **Positive Findings**
   - Effective controls that prevented worse impact
   - Successful procedures
   - Well-performing teams/individuals

2. **Areas for Improvement**
   - Control gaps (specific ISO 27001 controls or DORA articles)
   - Procedural weaknesses
   - Skills/training needs

3. **Recommendations**
   - To management board
   - To operational teams
   - To third-party providers

### Section R: Cross-Border and Regulatory Impact

1. **Other EU Member States Affected**
   - Countries and impact description

2. **Other Regulatory Notifications**
   - Data protection authorities (GDPR breaches)
   - Other sectoral regulators
   - Law enforcement

3. **Client Notifications**
   - Number of clients notified individually
   - Public disclosure (if applicable)

### Section S: Certification

**Declaration by Reporting Entity:**

"We hereby confirm that the information provided in this final report is complete and accurate to the best of our knowledge as of [date]. The incident has been thoroughly investigated, and the remediation measures outlined have been approved by the management body."

- Name: _______________
- Position: _______________ (e.g., CISO, CRO)
- Date: _______________
- Signature: _______________

## Submission Methods

### Technical Formats

**Accepted Formats:**
1. **Online Portal** (preferred)
   - Web form with sections above
   - Real-time validation
   - Automatic timestamp

2. **Structured Data (API)**
   - JSON schema provided by competent authority
   - Automated submission from SIEM/incident management tools
   - API authentication (OAuth 2.0, API keys)

3. **Email (backup)**
   - PDF attachment (digitally signed)
   - Encrypted email (PGP or S/MIME)
   - Subject line: "DORA Incident Report - [IncidentID] - [ReportType]"

### Data Validation

**Mandatory Fields:**
- All Section A-B fields (entity and incident identification)
- Detection time (Section C)
- Incident type (Section D)
- At least one affected system (Section E)
- At least one response action (Section F)

**Optional but Recommended:**
- IoC section (critical for threat intelligence sharing)
- Financial impact (for regulatory risk assessment)

## Automation & Integration

### SIEM Integration

**Auto-Population from SIEM:**
- Detection time → SIEM alert timestamp
- Affected systems → SIEM asset inventory
- IoC → SIEM threat intelligence module
- Attack vectors → SIEM correlation rules triggered

**Example SIEM Connectors:**
- Splunk: Custom DORA incident report app
- Elastic: Logstash output plugin to authority portal
- QRadar: Offense to DORA report mapper

### Incident Management Tool Integration

**ServiceNow / Jira Service Management:**
- Custom DORA incident form template
- Workflow: Incident created → Severity assessment → If major → Auto-draft DORA report
- Approval workflow before submission

## Common Mistakes to Avoid

**Initial Notification Errors:**
1. ❌ Waiting for complete information (violates 4-hour deadline)
2. ❌ Underestimating severity ("let's wait and see")
3. ❌ Missing IoC section (loses threat intelligence value)
4. ❌ Incomplete contact information (delays authority follow-up)

**Intermediate Report Errors:**
1. ❌ Not updating if situation unchanged ("nothing new to report" → still send update)
2. ❌ Missing timeline updates
3. ❌ Failure to report increased impact

**Final Report Errors:**
1. ❌ Superficial root cause analysis (authorities will challenge)
2. ❌ Missing financial impact (required for regulatory assessment)
3. ❌ No lessons learned (shows lack of continuous improvement)
4. ❌ Unrealistic remediation timelines (overpromising)

## Best Practices

**Template Pre-Population:**
- Maintain entity information in template (Section A)
- Pre-configure SIEM to fill technical sections
- Regular drills to test reporting capability

**Quality Assurance:**
- Peer review before submission (second pair of eyes)
- Escalate to CISO/CRO for major incidents
- Management board informed in parallel

**Evidence Retention:**
- Keep incident investigation files (forensics, logs) for minimum 5 years
- Link DORA report to internal incident ticket
- Store all report versions (audit trail)

## Resources

- **Official Text**: https://eur-lex.europa.eu/eli/reg_impl/2024/1502/oj
- **Template Downloads**: Competent authority portals (BaFin, ECB, etc.)
- **API Specifications**: Authority-specific (request from competent authority)

## Updates Log

- **January 17, 2025**: ITS 2024/1502 application date (templates mandatory)
- **June 3, 2024**: Published in Official Journal
- **May 29, 2024**: Adopted by European Commission
