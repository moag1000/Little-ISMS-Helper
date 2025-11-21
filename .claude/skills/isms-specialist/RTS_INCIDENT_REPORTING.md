# DORA RTS - Incident Reporting

**Commission Delegated Regulation (EU) 2024/1773**

## Official Information

- **Full Title**: Commission Delegated Regulation (EU) 2024/1773 of 13 March 2024 supplementing Regulation (EU) 2022/2554 with regard to regulatory technical standards specifying the criteria for the classification of ICT-related incidents and cyber threats and for determining their materiality and the details of reports of major ICT-related incidents
- **Adopted**: March 13, 2024
- **Delegated**: July 17, 2024
- **Published**: Official Journal L 1773, July 19, 2024
- **Application Date**: January 17, 2025
- **Legal Basis**: DORA Article 20 (ICT-related incident reporting)
- **Official Text**: https://eur-lex.europa.eu/eli/reg_del/2024/1773/oj
- **Related ITS**: Commission Implementing Regulation (EU) 2024/1502 (reporting templates)

## Scope

Applies to all financial entities covered by DORA Article 2, specifying:
1. Criteria for classifying ICT-related incidents and cyber threats
2. Criteria for determining materiality
3. Details of incident reports (initial, intermediate, final)

## Structure

### Chapter I: General Provisions (Articles 1-3)
- Article 1: Subject matter
- Article 2: Definitions
- Article 3: Scope

### Chapter II: Classification of ICT-Related Incidents (Articles 4-7)

**Article 4: Classification Criteria**
ICT-related incidents shall be classified based on:
1. **Impact**: Effect on operations, customers, counterparties
2. **Severity**: Criticality of affected systems
3. **Duration**: Length of disruption
4. **Data Loss**: Volume and sensitivity
5. **Geographical Spread**: Number of affected locations/countries
6. **Economic Impact**: Financial losses

**Article 5: Major vs. Significant Incidents**

**Major ICT-Related Incident** must meet at least one of:
- **High impact** on operations, customers, or market
- **Affects critical or important functions**
- **Data loss** involving sensitive/confidential data
- **Duration** exceeds established thresholds
- **Reputational damage** or regulatory scrutiny expected

**Significant Cyber Threat**: Indicators suggest potential for major incident

**Article 6: Materiality Thresholds**

Quantitative thresholds for determining major incidents:
- **Transactions**: >10% of daily transactions disrupted
- **Clients**: >10% of clients affected or >100,000 clients
- **Services**: Critical/important function unavailable >2 hours (Tier 1) or >4 hours (Tier 2)
- **Financial Impact**: Loss >€50,000 or potential loss >€500,000
- **Data**: Breach affecting >100,000 individuals or high-value targets
- **Geographical**: Affecting >2 Member States

**Article 7: Classification Methodology**

Financial entities must:
- Define classification criteria aligned with Article 5
- Document methodology
- Regularly review and update
- Train staff on classification

### Chapter III: Reporting Details (Articles 8-12)

**Article 8: Initial Notification (≤4 hours)**

**Timeline**: As soon as significant impact becomes apparent, no later than **4 hours**

**Minimum Content:**
1. **Incident Identification**
   - Unique incident ID
   - Date and time of detection
   - Reporting entity details

2. **Initial Assessment**
   - Preliminary classification (major/significant)
   - Affected systems/services
   - Number of affected clients (estimate)
   - Geographical scope

3. **Immediate Actions**
   - Containment measures taken
   - Activation of business continuity plans
   - Communication to clients/stakeholders

4. **Indicators of Compromise (IoC)**
   - IP addresses, domains involved
   - Malware hashes (if applicable)
   - Attack vectors observed

**Article 9: Intermediate Report**

**Timeline**: Regular updates while incident is ongoing

**Content Updates:**
1. **Incident Evolution**
   - Changes in severity/impact
   - Additional affected systems
   - Updated client impact numbers

2. **Root Cause Analysis (preliminary)**
   - Attack type/method
   - Vulnerabilities exploited
   - Internal/external factors

3. **Response Measures**
   - Technical mitigation steps
   - Forensic activities
   - Third-party involvement (e.g., law enforcement)

4. **Estimated Recovery**
   - Expected time to resolution
   - Recovery progress percentage

**Article 10: Final Report (≤1 month)**

**Timeline**: Within **1 month** after initial notification (may be extended to 2 months for complex incidents)

**Comprehensive Content:**
1. **Detailed Description**
   - Complete incident timeline
   - All affected systems and services
   - Final client impact numbers
   - Financial impact (actual and potential)

2. **Root Cause Analysis (final)**
   - Confirmed attack type
   - Detailed vulnerability analysis
   - Contributing factors (technical, procedural, human)

3. **Response and Recovery**
   - All mitigation measures taken
   - Effectiveness of business continuity plans
   - Lessons learned

4. **Preventive Measures**
   - Short-term remediation actions
   - Long-term improvements planned
   - Changes to policies/procedures

5. **Third-Party Involvement**
   - ICT third-party service providers involved
   - Law enforcement notifications
   - Regulatory notifications (other authorities)

6. **Cross-Border Impact**
   - Other EU Member States affected
   - International implications

**Article 11: Voluntary Notification of Cyber Threats**

Financial entities may report significant cyber threats that have not yet caused an incident:
- Threat intelligence indicators
- Observed reconnaissance activities
- Attempted attacks (unsuccessful)
- Emerging threat patterns

**Article 12: Reporting Channels**

- Reports submitted to **competent authority**
- Use of standardized templates (ITS 2024/1502)
- Secure communication channels
- Automated reporting systems where available

### Chapter IV: Final Provisions (Article 13)

## Major Incident Classification - Detailed Criteria

### Impact-Based Classification

**Operational Impact:**
- **Critical services** unavailable or degraded
- **Transactions** disrupted (>10% of daily volume)
- **Payment processing** affected
- **Trading activities** suspended
- **Client access** to accounts/services restricted

**Client Impact:**
- **Number of clients** affected (>10% or >100,000)
- **Vulnerability** of client groups (retail, SME, institutional)
- **Financial loss** to clients
- **Data breach** affecting client PII

**Market Impact:**
- **Trading venue** disruption
- **Settlement** delays
- **Liquidity** issues
- **Price discovery** affected
- **Market abuse** potential

**Reputational Impact:**
- **Media coverage** (national/international)
- **Social media** attention
- **Client complaints** surge
- **Regulatory scrutiny** anticipated
- **Credit rating** implications

### Duration Thresholds

**Tier 1 Entities** (systemically important):
- **>2 hours** for critical functions
- **>4 hours** for important functions
- **Any duration** if major data breach

**Tier 2 Entities**:
- **>4 hours** for critical functions
- **>8 hours** for important functions

**Tier 3 Entities** (smaller):
- **>8 hours** for critical functions
- **>24 hours** for important functions

### Data Loss Classification

**Sensitivity Levels:**
1. **Public data**: No classification as major unless volume extreme
2. **Internal data**: Major if >1 million records
3. **Confidential data**: Major if >100,000 records
4. **Strictly confidential**: Major if >1,000 records or any high-value target (HNWI, corporate secrets)

**Personal Data (GDPR linkage):**
- **Special categories** (Art. 9 GDPR): Any breach is major
- **Financial data**: Major if >10,000 individuals
- **Authentication credentials**: Major if >1,000 accounts
- **Payment card data**: Major if any PCI DSS breach

### Financial Impact Thresholds

**Direct Losses:**
- **Actual loss**: >€50,000 (immediate classification)
- **Potential loss**: >€500,000 (if materialization likely)
- **Regulatory fines**: Expected penalties >€100,000
- **Remediation costs**: >€200,000

**Indirect Losses:**
- **Business interruption**: Revenue loss >€100,000/day
- **Reputational damage**: Estimated impact >€500,000
- **Client compensation**: Expected payouts >€250,000

### Geographical Spread

**Cross-Border Incidents:**
- **>2 EU Member States** affected: Automatic major classification
- **Non-EU countries** with significant operations: Report if material impact
- **Third-party provider** incident affecting multiple entities

## Reporting Timeline - Practical Implementation

### 4-Hour Initial Notification

**Clock Starts When:**
- Incident **detected** by monitoring systems, OR
- Incident **reported** by client/third party, OR
- **Significant impact** becomes apparent

**Preparatory Actions (ongoing):**
1. Pre-configure reporting templates
2. Establish 24/7 incident response team
3. Define notification tree (who approves report)
4. Automate data gathering (affected systems, client count)

**Within 4 Hours:**
1. **Minute 0-30**: Incident detection, initial triage
2. **Minute 30-90**: Classification, impact assessment
3. **Minute 90-180**: Prepare initial notification
4. **Minute 180-240**: Review, approve, submit

**Practical Tips:**
- Use incident response playbooks
- Pre-fill template fields (entity details)
- Automate impact metrics gathering
- Have dedicated communication team
- 24/7 approval authority defined

### Intermediate Reports

**Frequency:**
- **First 24 hours**: Every 12 hours minimum
- **Day 2-7**: Daily updates
- **After day 7**: Every 3-5 days until resolution

**Trigger for Intermediate Report:**
- **Significant change** in severity
- **New affected systems** discovered
- **Root cause** identified
- **Recovery milestone** achieved
- **Authority request** for update

### Final Report (1 month)

**Preparation Timeline:**
- **Week 1-2**: Forensic analysis, data collection
- **Week 3**: Root cause analysis, lessons learned workshops
- **Week 4**: Report drafting, review, approval
- **Submit**: Before 1-month deadline

**Extension Request:**
- Submit **before** 1-month deadline
- **Justification**: Complexity, ongoing forensics, third-party dependencies
- **Maximum extension**: 1 additional month (total 2 months)

## ISO 27001:2022 Integration

| Reporting Requirement | ISO 27001 Control | Notes |
|-----------------------|-------------------|-------|
| Incident classification | A.5.24 (Incident planning) | ISO process + DORA criteria |
| Detection & monitoring | A.8.16 (Monitoring) | Automated detection for 4h timeline |
| Incident response | A.5.25-A.5.27 | Existing process + DORA reporting |
| Communication | A.5.26 (Response to incidents) | Add authority notification |
| Post-incident review | A.5.28 (Lessons learned) | Input to final report |
| Evidence preservation | A.5.27 (Evidence collection) | For root cause analysis |

**ISO 27001 Gap:**
- **4-hour reporting**: Not specified in ISO 27001 (requires process enhancement)
- **Standardized templates**: ISO allows flexibility, DORA requires ITS 2024/1502 templates
- **Quantitative thresholds**: ISO is qualitative, DORA has specific numbers

## Implementation Checklist

### Preparation Phase
- [ ] Define major vs. significant incident criteria (aligned with Article 5-6)
- [ ] Document classification methodology
- [ ] Establish quantitative thresholds for entity
- [ ] Configure reporting templates (ITS 2024/1502)
- [ ] Set up secure reporting channel to competent authority
- [ ] Train incident response team on DORA reporting
- [ ] Conduct tabletop exercises

### Detection & Monitoring
- [ ] Deploy 24/7 monitoring (SIEM, SOC)
- [ ] Configure automated alerts for major incident indicators
- [ ] Integrate client impact metrics (real-time dashboards)
- [ ] Financial impact tracking system
- [ ] Geographical spread monitoring

### Incident Response Integration
- [ ] Update incident response playbooks with DORA reporting steps
- [ ] Define notification tree (IR team → CISO → Management → Authority)
- [ ] 24/7 approval authority designated
- [ ] Automated initial notification draft generation
- [ ] Forensic readiness (tools, procedures, trained personnel)

### Reporting Execution
- [ ] Initial notification capability (≤4 hours)
- [ ] Intermediate report process
- [ ] Final report template and process
- [ ] Evidence management system
- [ ] Lessons learned process

### Governance & Oversight
- [ ] Management body briefing on major incidents
- [ ] Regulatory liaison designated
- [ ] Post-incident review board
- [ ] Continuous improvement process

## Common Incident Scenarios

### Scenario 1: Ransomware Attack

**Detection**: Encryption of critical systems detected at 02:00
**Classification**: Major (critical services affected, >2h downtime expected)

**Reporting Timeline:**
- **02:00**: Detection
- **02:30**: Classification as major
- **05:45**: Initial notification submitted (within 4h)
- **14:00**: Intermediate report #1 (12h later)
- **Day 2-7**: Daily intermediate reports
- **Day 30**: Final report submitted

**Initial Notification Content:**
- Ransomware attack, critical systems encrypted
- Estimated 500 clients affected
- Business continuity plans activated
- Ransom demand received (amount not disclosed)
- Forensic analysis initiated

### Scenario 2: DDoS Attack

**Detection**: Trading platform unavailable due to DDoS at 09:00
**Classification**: Major if >2h, otherwise significant

**Decision Point at 11:00** (2h elapsed):
- If still ongoing → Major incident, report within 4h from detection (by 13:00)
- If resolved → Significant incident, voluntary reporting

### Scenario 3: Data Breach

**Detection**: Unauthorized access to client database discovered via SIEM alert at 16:00
**Classification**: Major (confidential client data)

**Immediate Actions:**
- Isolate affected systems
- Change credentials
- Engage forensics team

**Initial Notification (by 20:00):**
- Estimated 50,000 client records potentially accessed
- Financial data involved
- Data exfiltration not yet confirmed
- GDPR notification obligations triggered

## Tools & Technologies

**Incident Detection:**
- Splunk / Elastic SIEM
- CrowdStrike / Microsoft Defender
- Darktrace (AI-driven anomaly detection)
- Network monitoring (Nagios, Zabbix)

**Impact Assessment:**
- Business intelligence dashboards (Tableau, PowerBI)
- Real-time client transaction monitoring
- Service health dashboards

**Reporting Automation:**
- ServiceNow Security Incident Response
- IBM Resilient
- Palo Alto Cortex XSOAR
- Custom automation (API integration with authority portal)

**Forensics:**
- EnCase / FTK
- Volatility (memory forensics)
- Wireshark (network forensics)
- Log analysis tools

**Communication:**
- Mass notification systems (Everbridge)
- Secure messaging (Signal, encrypted email)
- War room collaboration (Slack, Teams with e2e encryption)

## Penalties for Non-Compliance

**Failure to Report:**
- Penalties up to **2% of global annual turnover** or **€10 million** (whichever higher)

**Late Reporting:**
- Proportionate penalties based on delay
- Aggravating factor in subsequent audits

**Incomplete Reporting:**
- Requirement to resubmit
- Potential penalties if material omissions

**False Reporting:**
- Serious breach, maximum penalties
- Management accountability

## Best Practices

**Before Incidents:**
1. Conduct annual DORA reporting drills
2. Pre-fill template sections (entity details, contact info)
3. Automate metric collection (client counts, financial impact)
4. Establish relationship with competent authority
5. Regular training for incident response team

**During Incidents:**
1. Follow playbook strictly
2. Don't delay initial notification (report with available info)
3. Update intermediate reports promptly
4. Preserve all evidence
5. Maintain detailed incident log

**After Incidents:**
1. Comprehensive lessons learned session
2. Update playbooks based on experience
3. Share anonymized details with industry peers
4. Implement preventive measures
5. Follow up with authority on any questions

## Links to Other DORA Requirements

**ICT Risk Management (RTS 2024/1772):**
- Incident response capabilities
- Monitoring and detection

**Resilience Testing (RTS 2024/1774):**
- Test incident response procedures
- Validate reporting timelines

**Third-Party Risk (RTS 2024/1932):**
- Third-party provider incidents
- Contractual notification obligations

## Resources

- **Official Text**: https://eur-lex.europa.eu/eli/reg_del/2024/1773/oj
- **ITS Templates (2024/1502)**: https://eur-lex.europa.eu/eli/reg_impl/2024/1502/oj
- **EBA Guidance**: https://www.eba.europa.eu/regulation-and-policy/operational-resilience/incident-reporting-under-dora
- **ENISA Incident Reporting**: https://www.enisa.europa.eu/

## Updates & Amendments

- **January 17, 2025**: RTS 2024/1773 application date
- **July 19, 2024**: Published in Official Journal
- **June 3, 2024**: ITS 2024/1502 (templates) published
- **March 13, 2024**: Adopted by European Commission