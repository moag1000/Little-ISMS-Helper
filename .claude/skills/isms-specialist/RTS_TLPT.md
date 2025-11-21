# DORA RTS - Threat-Led Penetration Testing (TLPT)

**Commission Delegated Regulation (EU) 2024/1774**

## Official Information

- **Full Title**: Commission Delegated Regulation (EU) 2024/1774 of 13 March 2024 supplementing Regulation (EU) 2022/2554 with regard to regulatory technical standards specifying the criteria for the application and methodologies for the conduct of the threat-led penetration testing
- **Adopted**: March 13, 2024
- **Delegated**: July 17, 2024
- **Published**: Official Journal L 1774, July 19, 2024
- **Application Date**: January 17, 2025
- **Legal Basis**: DORA Article 26 (Threat-led penetration testing)
- **Official Text**: https://eur-lex.europa.eu/eli/reg_del/2024/1774/oj

## Scope

Applies to financial entities meeting the criteria in DORA Article 26(3):
- Credit institutions (CRR Article 3(1)(1))
- Central counterparties (EMIR)
- Central securities depositories (CSDR)
- Trading venues (MiFID II)
- **Above significance thresholds** defined by ESAs

**Estimated entities in scope**: ~200-300 systemically important financial entities in EU

## TLPT Overview

### What is TLPT?

**Threat-Led Penetration Testing** is an advanced, intelligence-based security testing methodology that simulates real-world cyberattacks on live production systems by qualified ethical hackers to assess an organization's ability to withstand sophisticated attacks.

**Key Characteristics:**
- **Intelligence-led**: Based on real threat actor tactics, techniques, procedures (TTPs)
- **Red team vs. Blue team**: Attackers vs. Defenders
- **Live environment**: Testing on production systems (with safeguards)
- **Scenario-based**: Realistic attack scenarios
- **Holistic**: Technical + physical + social engineering
- **Independent**: External qualified testers

## Structure

### Chapter I: General Provisions (Articles 1-3)
- Article 1: Subject matter
- Article 2: Definitions
- Article 3: Scope and application

### Chapter II: TLPT Framework (Articles 4-11)

**Article 4: TLPT Participants**

**Mandatory Roles:**
1. **Financial Entity** (tested organization)
2. **Threat Intelligence Provider** (threat landscape analysis)
3. **Red Team** (attackers - external testers)
4. **Blue Team** (defenders - internal security team)
5. **White Team** (coordinators - oversight and control)
6. **Purple Team** (optional - knowledge transfer team)

**Article 5: Threat Intelligence**

TLPT must be based on **current threat intelligence**:
- Sector-specific threats
- Attack trends (ransomware, APT groups, etc.)
- Tactics, techniques, procedures (MITRE ATT&CK)
- Vulnerability landscape
- Geopolitical context

**Sources:**
- Commercial threat intelligence providers
- National CERTs/CSIRTs
- Information sharing communities (FS-ISAC, etc.)
- Open-source intelligence (OSINT)

**Article 6: TLPT Scenarios**

**Scenario Types:**
1. **External Perimeter Attack**
   - Internet-facing assets
   - Web applications, APIs
   - VPN, remote access
   - Email phishing

2. **Internal Network Compromise**
   - Lateral movement
   - Privilege escalation
   - Persistence mechanisms
   - Data exfiltration

3. **Physical Security**
   - Facility access
   - Server room intrusion
   - Tailgating, badge cloning

4. **Social Engineering**
   - Phishing campaigns
   - Pretexting
   - Phone-based attacks (vishing)
   - Physical social engineering

5. **Supply Chain Attack**
   - Third-party provider compromise
   - Software supply chain
   - Hardware implants

**Scenarios must simulate:**
- Advanced Persistent Threats (APT)
- Ransomware attack chains
- Insider threats
- Zero-day exploits (simulated)

**Article 7: TLPT Scope**

**In-Scope Systems:**
- **Critical functions** as defined by entity
- **Production systems** (live environment)
- **Key infrastructure**: Networks, data centers, cloud
- **Client-facing systems**: Online banking, trading platforms
- **ICT third-party connections**: Critical suppliers

**Out-of-Scope (usually):**
- Development/test environments (unless relevant)
- Disaster recovery sites (unless part of scenario)
- Legacy systems being decommissioned

**Article 8: TLPT Methodology**

**Five Phases:**

**Phase 1: Preparation (2-4 weeks)**
- Scope definition
- Threat intelligence gathering
- Scenario development
- Rules of engagement (ROE)
- Emergency contacts
- Testing schedule
- Legal agreements (contracts, NDAs)

**Phase 2: Testing Execution (4-12 weeks)**
- Red Team attack execution
- Blue Team defense (unaware of exact timing)
- White Team monitoring
- Controlled environment (safeguards)
- Real-time coordination
- Evidence collection

**Phase 3: Closure (1-2 weeks)**
- Attack stoppage
- System verification
- Preliminary findings
- Blue Team debrief

**Phase 4: Remediation (variable)**
- Vulnerability fixes
- Control enhancements
- Process improvements

**Phase 5: Re-Testing (optional)**
- Validate remediation
- Confirm fixes effective

**Article 9: Red Team Qualifications**

**Red Team must demonstrate:**
1. **Technical Expertise**
   - Penetration testing (OSCP, OSCE, GXPN)
   - Network security (GIAC certifications)
   - Application security (GWAPT, GPEN)
   - Cloud security (Azure Red Team, AWS Security)

2. **Threat Intelligence Skills**
   - MITRE ATT&CK framework
   - APT tactics analysis
   - Malware analysis

3. **Experience**
   - Minimum 5 years in offensive security
   - Previous TLPT engagements (financial sector preferred)
   - Team leader: 10+ years experience

4. **Ethical Standards**
   - Background checks
   - Confidentiality agreements
   - Professional certifications (CREST, OffSec, etc.)

**Prohibited:**
- Individuals with criminal records (cyber-related)
- Conflicts of interest
- Lack of E&O insurance

**Article 10: Rules of Engagement (ROE)**

**ROE Must Specify:**
1. **Scope boundaries**
   - IP ranges, domains
   - Physical locations
   - Excluded systems

2. **Constraints**
   - No data destruction
   - No DoS attacks on production
   - No ransomware deployment
   - Limited social engineering scope

3. **Timing**
   - Testing windows (e.g., avoid peak hours for some attacks)
   - Blackout periods (year-end, critical business periods)

4. **Emergency Procedures**
   - Incident escalation
   - Test abortion triggers
   - Emergency contacts (24/7)

5. **Communication Protocol**
   - Out-of-band communication
   - Code words for emergencies
   - Status updates

6. **Data Handling**
   - Exfiltrated data treatment
   - Evidence storage and deletion
   - Reporting confidentiality

**Article 11: TLPT Frequency**

**Mandatory Frequency**: At least once every **3 years**

**Triggers for Earlier Testing:**
- Major ICT infrastructure changes
- Significant cyberattack (actual incident)
- Merger/acquisition
- New critical services launched
- Regulatory requirement

### Chapter III: Reporting and Follow-Up (Articles 12-14)

**Article 12: TLPT Report**

**Report Structure:**
1. **Executive Summary**
   - Overall findings
   - Risk rating
   - Critical vulnerabilities

2. **Methodology**
   - Threat intelligence used
   - Scenarios tested
   - Tools and techniques

3. **Technical Findings**
   - Vulnerabilities discovered
   - Successful attack paths
   - Blue Team detection rates
   - Dwell time before detection

4. **Blue Team Performance**
   - Detection capabilities
   - Response effectiveness
   - Gaps in monitoring

5. **Recommendations**
   - Remediation priorities
   - Strategic improvements
   - Tactical fixes

**Report Distribution:**
- Financial entity management
- Competent authority (summary)
- Confidential (not publicly disclosed)

**Article 13: Remediation Plan**

Financial entity must develop remediation plan within **6 months**:
- Action items prioritized by risk
- Responsible owners
- Implementation timelines
- Budget allocation

**Article 14: Competent Authority Notification**

Financial entity notifies competent authority:
- **Before TLPT**: Planned testing (at least 3 months advance notice)
- **After TLPT**: Summary report (within 3 months after completion)

### Chapter IV: Final Provisions (Article 15)

## TLPT vs. Traditional Penetration Testing

| Aspect | TLPT | Traditional Pen Test |
|--------|------|---------------------|
| **Scope** | Holistic (technical + physical + social) | Usually technical only |
| **Environment** | Live production | Often test environment |
| **Duration** | 4-12 weeks | 1-4 weeks |
| **Approach** | Intelligence-led, APT simulation | Vulnerability scanning + exploitation |
| **Blue Team** | Active defender (unaware) | Often aware, may assist |
| **Frequency** | Every 3 years | Annually or more |
| **Cost** | €200,000 - €1,000,000+ | €20,000 - €100,000 |
| **Regulatory** | Mandated by DORA for large entities | Best practice, not always mandated |
| **Reporting** | To competent authority | Internal only |

## Implementation Guide

### Pre-TLPT Preparation (12 months before)

**Months 12-9: Internal Readiness**
- [ ] Assess current security maturity
- [ ] Conduct preliminary vulnerability assessments
- [ ] Strengthen baseline security (patch management, hardening)
- [ ] Enhance monitoring and logging (SIEM)
- [ ] Train Blue Team on incident response

**Months 9-6: Planning**
- [ ] Define scope (critical functions, in-scope systems)
- [ ] Budget approval (€200k - €1M+)
- [ ] Vendor selection (Red Team provider)
- [ ] Threat intelligence procurement
- [ ] Scenario development workshops

**Months 6-3: Procurement**
- [ ] Issue RFP for Red Team services
- [ ] Evaluate proposals (technical expertise, experience, cost)
- [ ] Due diligence on testers (background checks, certifications)
- [ ] Contract negotiation (ROE, liability, insurance)
- [ ] Threat intelligence provider engagement

**Months 3-1: Preparation**
- [ ] Notify competent authority (3 months advance notice)
- [ ] Finalize scenarios with threat intelligence
- [ ] Rules of Engagement approval (Legal, Risk, CISO)
- [ ] White Team formation (oversight)
- [ ] Blue Team preparation (without revealing exact dates)
- [ ] Communication plan (internal stakeholders, board)
- [ ] Emergency procedures tested

**Month 0: Execution**
- [ ] Kick-off meeting (Red, White teams)
- [ ] TLPT execution (4-12 weeks)
- [ ] Daily White Team monitoring
- [ ] Incident management (if production impact)
- [ ] Evidence collection

**Post-TLPT (within 6 months):**
- [ ] Report delivery and review
- [ ] Blue Team debrief
- [ ] Management presentation
- [ ] Remediation plan development
- [ ] Budget allocation for fixes
- [ ] Implementation of remediation
- [ ] Authority notification (summary report)

### Scenario Example: APT-Style Attack

**Scenario**: Advanced Persistent Threat targeting online banking platform

**Attack Chain:**

**Week 1: Reconnaissance**
- OSINT gathering (employees, technologies, partners)
- Passive network reconnaissance
- Social media profiling of key personnel
- Supplier identification

**Week 2-3: Initial Access**
- Phishing campaign (10 employees targeted)
- 2 employees fall for phishing → credentials harvested
- VPN access gained
- Establish C2 (Command & Control) channel

**Week 4-5: Lateral Movement**
- Credential dumping (Mimikatz-style)
- Privilege escalation
- Move from DMZ to internal network
- Discover domain administrator credentials

**Week 6-7: Persistence & Exfiltration**
- Create backdoor accounts
- Install persistence mechanisms (scheduled tasks, DLL hijacking)
- Locate customer database
- Exfiltrate sample data (100 records) to prove access
- **Blue Team Detection Point**: SIEM alerts on abnormal data access

**Week 8: Objective Achievement**
- Demonstrate ability to modify transactions
- Show ransomware deployment capability (simulated, not executed)
- Access to core banking system confirmed

**Blue Team Performance:**
- **Detection**: Day 45 (week 6-7) - better than 30% of entities
- **Containment**: Day 48 - good response time
- **Eradication**: Day 52 - thorough cleanup
- **Dwell Time**: 45 days - typical for advanced attacks

**Findings:**
- ✅ Detection capabilities adequate (SIEM alerts worked)
- ❌ Phishing awareness needs improvement (20% click rate)
- ❌ Lateral movement too easy (flat network, weak segmentation)
- ❌ Privileged accounts over-provisioned
- ✅ Incident response procedures followed correctly

## Tools & Techniques

**Red Team Toolkit:**
- **Reconnaissance**: Maltego, Shodan, theHarvester, OSINT Framework
- **Exploitation**: Metasploit, Cobalt Strike, Empire, Covenant
- **Post-Exploitation**: Mimikatz, BloodHound, PowerView, CrackMapExec
- **Persistence**: Custom backdoors, web shells, rootkits
- **C2 Infrastructure**: Cloud-hosted (AWS, Azure), domain fronting
- **Social Engineering**: GoPhish, SET (Social-Engineer Toolkit)
- **Physical**: Lock picking, RFID cloning, camera evasion

**Blue Team Detection:**
- **SIEM**: Splunk, Elastic, QRadar
- **EDR**: CrowdStrike, Microsoft Defender for Endpoint, SentinelOne
- **NDR**: Darktrace, Vectra, ExtraHop
- **UEBA**: Exabeam, Gurucul
- **Threat Hunting**: Custom scripts, YARA rules, Sigma rules

## Costs & ROI

**Typical TLPT Costs:**
- **Small Entity** (€10B assets): €150,000 - €300,000
- **Medium Entity** (€50B assets): €300,000 - €600,000
- **Large Entity** (€200B+ assets): €600,000 - €1,500,000

**Cost Breakdown:**
- Red Team services: 60-70%
- Threat intelligence: 10-15%
- White Team coordination: 10-15%
- Remediation: 20-30% (separate budget)

**ROI Calculation:**
- **Prevented breach cost**: Average €4M (Ponemon Institute)
- **TLPT cost**: €500,000
- **ROI**: If TLPT prevents one major breach in 3 years = 700% ROI

**Non-Financial Benefits:**
- Regulatory compliance (DORA requirement)
- Improved security posture
- Enhanced incident response capability
- Board confidence
- Competitive advantage (demonstrable resilience)

## Vendor Selection Criteria

**Must-Have Qualifications:**
- [ ] CREST certified (or equivalent)
- [ ] Financial sector TLPT experience (minimum 3 engagements)
- [ ] Team certifications (OSCP, OSCE, GXPN, etc.)
- [ ] E&O insurance (minimum €5M coverage)
- [ ] Background-checked personnel
- [ ] ISO 27001 certified provider
- [ ] References from similar entities

**Evaluation Criteria:**
- Technical expertise (40%)
- Financial sector experience (25%)
- Methodology and approach (20%)
- Cost (10%)
- Cultural fit and communication (5%)

**Red Flags:**
- Overly aggressive sales tactics
- Lack of financial sector experience
- Unwillingness to provide references
- No formal methodology
- Inadequate insurance coverage
- Team members with questionable backgrounds

## Common Challenges & Solutions

**Challenge 1: Blue Team Unpreparedness**
- **Issue**: Blue Team overwhelmed, incident response gaps
- **Solution**: Conduct Purple Team exercises before TLPT, improve playbooks

**Challenge 2: Production Impact**
- **Issue**: TLPT causes unintended outage
- **Solution**: Robust ROE, staged approach, White Team monitoring, rollback procedures

**Challenge 3: Scope Creep**
- **Issue**: Red Team exceeds agreed scope, tests prohibited systems
- **Solution**: Clear ROE, White Team oversight, kill switches, regular check-ins

**Challenge 4: Expensive Remediation**
- **Issue**: TLPT reveals €2M+ remediation needs, budget not available
- **Solution**: Risk-based prioritization, multi-year remediation plan, board engagement

**Challenge 5: Competent Authority Concerns**
- **Issue**: Authority raises questions about findings, requests additional testing
- **Solution**: Proactive communication, comprehensive remediation plan, demonstrate improvements

## Regulatory Expectations

**Competent Authority Expectations:**
1. **Advance Notice**: 3 months before TLPT (Article 14)
2. **Qualified Testers**: Verified credentials and background
3. **Comprehensive Scope**: All critical functions included
4. **Realistic Scenarios**: Based on current threats
5. **Effective Blue Team**: Capable of detecting and responding
6. **Remediation**: Plan within 6 months, execution within reasonable timeframe
7. **Summary Report**: Submitted within 3 months post-TLPT

**Audit Questions (Post-TLPT):**
- Was TLPT conducted by qualified external testers?
- Were scenarios based on current threat intelligence?
- What was the Blue Team detection rate?
- What were the critical findings?
- Has remediation plan been implemented?
- When is the next TLPT scheduled?

## Integration with Other DORA Requirements

**ICT Risk Management (RTS 2024/1772):**
- TLPT validates risk management effectiveness
- Findings feed into risk assessment updates

**Incident Reporting (RTS 2024/1773):**
- Simulated incidents test reporting procedures
- Validates 4-hour notification capability

**Third-Party Risk (RTS 2024/1932):**
- Include third-party connections in TLPT scope
- Test supplier security integration

## Resources

- **Official Text**: https://eur-lex.europa.eu/eli/reg_del/2024/1774/oj
- **TIBER-EU Framework**: https://www.ecb.europa.eu/pub/pdf/other/ecb.tiber_eu_framework.en.pdf (basis for DORA TLPT)
- **CREST**: https://www.crest-approved.org/examination/threat-intelligence-led-penetration-testing/
- **MITRE ATT&CK**: https://attack.mitre.org/

## Best Practices

**Before TLPT:**
1. Achieve baseline security maturity (ISO 27001 certified ideally)
2. Conduct internal penetration tests annually
3. Purple Team exercises quarterly
4. Threat intelligence program established
5. Incident response tested and proven

**During TLPT:**
1. White Team daily standups
2. Blue Team operates normally (no hints)
3. Document everything
4. Escalation procedures ready
5. Stakeholder communication plan active

**After TLPT:**
1. Comprehensive debrief (lessons learned)
2. Prioritized remediation roadmap
3. Quick wins implemented immediately
4. Long-term improvements planned
5. Share learnings (anonymized) with peers
6. Continuous improvement mindset

## Updates & Amendments

- **January 17, 2025**: RTS 2024/1774 application date
- **July 19, 2024**: Published in Official Journal
- **March 13, 2024**: Adopted by European Commission
