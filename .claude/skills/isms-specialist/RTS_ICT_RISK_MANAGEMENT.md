# DORA RTS - ICT Risk Management Framework

**Commission Delegated Regulation (EU) 2024/1772**

## Official Information

- **Full Title**: Commission Delegated Regulation (EU) 2024/1772 of 13 March 2024 supplementing Regulation (EU) 2022/2554 with regard to regulatory technical standards specifying ICT risk management tools, methods, processes and policies and the simplified ICT risk management framework
- **Adopted**: March 13, 2024
- **Delegated**: July 17, 2024
- **Published**: Official Journal L 1772, July 19, 2024
- **Application Date**: January 17, 2025
- **Legal Basis**: DORA Article 15 (ICT risk management framework)
- **Official Text**: https://eur-lex.europa.eu/eli/reg_del/2024/1772/oj

## Scope

Applies to all financial entities covered by DORA Article 2, specifying:
1. ICT risk management tools, methods, processes and policies
2. Elements of the simplified ICT risk management framework for microenterprises

## Structure

### Chapter I: General Provisions (Articles 1-2)
- Article 1: Subject matter
- Article 2: Definitions

### Chapter II: ICT Risk Management Framework (Articles 3-12)

**Article 3: ICT Strategy**
Financial entities shall establish an ICT strategy that:
- Aligns with business strategy
- Covers objectives for ICT capabilities
- Includes risk appetite for ICT risk
- Defines budget allocation
- Is approved by management body

**Article 4: ICT Risk Governance**
- Clear roles and responsibilities
- Three lines of defense model
- Reporting lines to management body
- Segregation of duties

**Article 5: Internal Control Mechanisms**
- ICT risk control framework
- Regular reviews and updates
- Documentation requirements
- Audit trail

**Article 6: ICT Asset Management**
- Comprehensive inventory of:
  - Hardware
  - Software
  - Network infrastructure
  - Data assets
  - Personnel with ICT responsibilities
- Asset classification based on:
  - Criticality
  - Sensitivity
  - Legal/regulatory requirements
- Configuration management
- Dependency mapping

**Article 7: Change Management**
- Formal change management process
- Testing procedures before production
- Rollback procedures
- Emergency change procedures
- Documentation of changes

**Article 8: Patch Management**
- Timely patching of vulnerabilities
- Risk-based prioritization
- Testing before deployment
- Emergency patching procedures
- Documentation and monitoring

**Article 9: Network Security**
- Network segmentation
- Monitoring of network traffic
- Intrusion detection/prevention systems
- Firewall management
- Secure remote access

**Article 10: Physical Security Controls**
- Access controls to ICT facilities
- Environmental controls
- Physical security monitoring
- Media handling procedures

**Article 11: Data Security**
- Data classification
- Encryption requirements
- Data backup procedures
- Data loss prevention
- Secure data deletion

**Article 12: ICT Project Management**
- ICT project governance
- Security requirements in projects
- Testing and acceptance procedures
- Post-implementation review

### Chapter III: Simplified Framework for Microenterprises (Articles 13-14)

**Article 13: Proportionality Principle**
Microenterprises may apply simplified measures while ensuring:
- Appropriate protection level
- Core security objectives met
- Compliance with DORA minimum requirements

**Article 14: Simplified Measures**
Simplified approaches for:
- Documentation (lighter)
- Risk assessment (basic)
- Testing (simplified)
- Incident management (streamlined)

### Chapter IV: Final Provisions (Article 15)

## Key Requirements

### 1. ICT Risk Management Framework Components

**Minimum Required Elements:**
1. ✅ ICT strategy aligned with business objectives
2. ✅ Governance structure (roles, responsibilities)
3. ✅ Risk assessment methodology
4. ✅ Asset inventory and classification
5. ✅ Change and patch management
6. ✅ Network and data security controls
7. ✅ Business continuity arrangements
8. ✅ Testing and monitoring procedures
9. ✅ Incident management processes
10. ✅ Documentation and reporting

### 2. ICT Asset Management (Article 6)

**Asset Inventory Must Include:**
- **Hardware**: Servers, workstations, mobile devices, network equipment
- **Software**: Operating systems, applications, databases, middleware
- **Network Infrastructure**: Routers, switches, firewalls, load balancers
- **Data Assets**: Databases, data repositories, backup locations
- **Cloud Services**: SaaS, PaaS, IaaS services
- **ICT Personnel**: Internal staff, external contractors, third-party providers

**Asset Classification Criteria:**
- Criticality (business impact if unavailable)
- Sensitivity (confidentiality requirements)
- Legal/regulatory requirements
- Recovery time objectives (RTO)
- Recovery point objectives (RPO)

**Asset Dependencies:**
- Upstream dependencies (services relied upon)
- Downstream dependencies (services dependent on asset)
- Cross-dependencies
- Third-party dependencies

### 3. Change Management Process (Article 7)

**Change Types:**
1. **Standard Changes**: Pre-approved, low-risk, routine
2. **Normal Changes**: Require formal approval, tested
3. **Emergency Changes**: Urgent, require post-implementation review

**Required Steps:**
1. Change request and documentation
2. Risk assessment and impact analysis
3. Testing in non-production environment
4. Approval by authorized personnel
5. Implementation scheduling
6. Rollback plan preparation
7. Communication to stakeholders
8. Post-implementation review
9. Documentation update

### 4. Patch Management (Article 8)

**Patch Classification:**
- **Critical**: Vulnerabilities actively exploited - immediate patching
- **High**: Serious vulnerabilities - within 7 days
- **Medium**: Moderate vulnerabilities - within 30 days
- **Low**: Minor issues - within 90 days

**Patch Management Process:**
1. Vulnerability monitoring (CVE databases, vendor advisories)
2. Risk assessment (CVSS score, exploitability, business impact)
3. Patch testing (in test environment)
4. Deployment scheduling
5. Implementation
6. Verification
7. Documentation

**Emergency Patching:**
- Criteria for emergency deployment
- Expedited approval process
- Immediate testing (parallel to deployment if necessary)
- Post-deployment validation

### 5. Network Security Controls (Article 9)

**Network Segmentation:**
- Separate production, development, testing environments
- DMZ for external-facing services
- Privileged access network isolation
- Data center segmentation

**Monitoring Requirements:**
- Real-time network traffic monitoring
- Anomaly detection
- Log collection and analysis
- Security Information and Event Management (SIEM)

**Network Security Tools:**
- Firewalls (next-generation)
- Intrusion Detection Systems (IDS)
- Intrusion Prevention Systems (IPS)
- DDoS protection
- Web Application Firewalls (WAF)

### 6. Data Security Measures (Article 11)

**Data Classification Levels:**
1. **Public**: No confidentiality requirement
2. **Internal**: Internal use only
3. **Confidential**: Restricted access
4. **Strictly Confidential**: Highly restricted

**Encryption Requirements:**
- Data at rest: AES-256 or equivalent
- Data in transit: TLS 1.3 or equivalent
- Key management: Secure key storage, rotation, destruction
- Crypto-agility: Ability to upgrade algorithms

**Data Backup:**
- Backup frequency based on RPO
- Multiple backup copies (3-2-1 rule)
- Offsite/offline backups
- Regular restore testing
- Encryption of backups

## ISO 27001:2022 Mapping

| RTS Article | Requirement | ISO 27001 Controls | Coverage |
|-------------|-------------|-------------------|----------|
| Art. 3 | ICT strategy | Clause 4.2, 6.2 | 90% |
| Art. 4 | ICT governance | Clause 5.3, A.5.2 | 100% |
| Art. 5 | Internal controls | Clause 9.1, 9.2 | 100% |
| Art. 6 | Asset management | A.5.9, A.5.10 | 100% |
| Art. 7 | Change management | A.8.32 | 100% |
| Art. 8 | Patch management | A.8.8 | 100% |
| Art. 9 | Network security | A.8.20-A.8.23 | 95% |
| Art. 10 | Physical security | A.7.1-A.7.14 | 100% |
| Art. 11 | Data security | A.8.10-A.8.12, A.8.24 | 100% |
| Art. 12 | ICT projects | A.8.25-A.8.34 | 90% |

**Overall Mapping: ~95%** - ISO 27001 provides excellent coverage of RTS requirements

## Implementation Checklist

### Phase 1: ICT Strategy & Governance (Articles 3-5)
- [ ] Develop ICT strategy aligned with business strategy
- [ ] Define ICT risk appetite
- [ ] Establish governance structure
- [ ] Define roles and responsibilities (three lines of defense)
- [ ] Implement internal control mechanisms
- [ ] Obtain management body approval

### Phase 2: Asset Management (Article 6)
- [ ] Create comprehensive asset inventory
- [ ] Classify assets by criticality and sensitivity
- [ ] Map asset dependencies
- [ ] Implement configuration management
- [ ] Establish asset lifecycle management
- [ ] Regular inventory updates (at least annually)

### Phase 3: Change & Patch Management (Articles 7-8)
- [ ] Define change management process (standard/normal/emergency)
- [ ] Implement change approval workflow
- [ ] Establish testing procedures
- [ ] Create rollback procedures
- [ ] Implement patch management process
- [ ] Define patching timelines based on severity
- [ ] Set up vulnerability monitoring

### Phase 4: Network Security (Article 9)
- [ ] Implement network segmentation
- [ ] Deploy firewalls and IDS/IPS
- [ ] Configure network monitoring
- [ ] Implement SIEM solution
- [ ] Establish secure remote access
- [ ] Document network architecture

### Phase 5: Physical & Data Security (Articles 10-11)
- [ ] Implement physical access controls
- [ ] Deploy environmental controls
- [ ] Classify all data assets
- [ ] Implement encryption (at rest and in transit)
- [ ] Establish backup procedures
- [ ] Test data restoration
- [ ] Implement data loss prevention (DLP)

### Phase 6: ICT Project Management (Article 12)
- [ ] Define ICT project governance
- [ ] Integrate security into SDLC
- [ ] Establish testing and acceptance criteria
- [ ] Implement post-implementation review

### Phase 7: Documentation & Evidence
- [ ] Document all processes and procedures
- [ ] Maintain audit trails
- [ ] Create evidence repository
- [ ] Regular documentation reviews

## Microenterprises - Simplified Framework (Articles 13-14)

**Simplified Requirements:**

**Instead of comprehensive asset inventory:**
- Basic inventory of critical ICT assets only
- Simplified classification (critical/non-critical)

**Instead of formal change management:**
- Basic change log
- Testing for critical changes only
- Simplified approval (one person can approve non-critical)

**Instead of detailed network security:**
- Basic firewall
- Antivirus/anti-malware
- Secure Wi-Fi

**Instead of SIEM:**
- Basic logging of critical systems
- Manual log review (weekly/monthly)

**Documentation:**
- Lighter documentation requirements
- Templates provided by authorities
- Focus on essential controls

## Common Implementation Challenges

**Challenge 1: Asset Discovery**
- **Issue**: Unknown/shadow IT assets
- **Solution**: Automated discovery tools, network scanning, CMDB

**Challenge 2: Change Management Overhead**
- **Issue**: Process too bureaucratic, slows down business
- **Solution**: Risk-based approach, pre-approved standard changes, automation

**Challenge 3: Patching Windows**
- **Issue**: Business requires 24/7 uptime
- **Solution**: High availability architecture, rolling patches, maintenance windows

**Challenge 4: Network Segmentation Complexity**
- **Issue**: Legacy systems, complex dependencies
- **Solution**: Phased approach, start with critical systems, microsegmentation

**Challenge 5: Encryption Key Management**
- **Issue**: Key sprawl, lost keys, compliance
- **Solution**: Centralized key management system (KMS), HSM for critical keys

## Best Practices

**Asset Management:**
- Use automated discovery tools (e.g., Qualys, Nessus, ServiceNow)
- Implement CMDB with automated updates
- Regular reconciliation (quarterly)
- Integrate with procurement process

**Change Management:**
- Automate change workflow (ServiceNow, Jira)
- Standard change catalog (reduce approvals)
- DevOps/CI/CD integration
- Rollback automation

**Patch Management:**
- Automated patch deployment (WSUS, SCCM, Ansible)
- Staged rollout (test → pre-prod → prod)
- Exception tracking with risk acceptance
- Metrics dashboards (patch compliance %)

**Network Security:**
- Zero Trust architecture
- Micro-segmentation
- Software-defined networking (SDN)
- Continuous monitoring with AI/ML

**Data Security:**
- Data classification automation (DLP tools)
- Encryption by default
- Cloud-native encryption (AWS KMS, Azure Key Vault)
- Regular encryption key rotation

## Tools & Technologies

**Asset Management:**
- ServiceNow CMDB
- Microsoft System Center Configuration Manager (SCCM)
- Qualys Asset Inventory
- Nessus
- Tenable.io

**Change Management:**
- ServiceNow Change Management
- Jira Service Management
- BMC Remedy
- ManageEngine ServiceDesk Plus

**Patch Management:**
- Microsoft WSUS/SCCM
- Red Hat Satellite
- Ivanti Patch Management
- Automox
- Ansible for automation

**Network Security:**
- Palo Alto Networks (NGFW)
- Cisco Firepower
- Fortinet FortiGate
- Splunk / Elastic SIEM
- Darktrace (AI-driven)

**Data Security:**
- Varonis (DLP)
- Symantec DLP
- Microsoft Purview
- AWS KMS / Azure Key Vault
- Thales HSM

## Audit & Verification

**Evidence Required:**
- ✅ ICT strategy document (approved by management)
- ✅ Governance framework document
- ✅ Asset inventory (current, ≤12 months old)
- ✅ Change management records (last 12 months)
- ✅ Patch management reports (compliance metrics)
- ✅ Network architecture diagrams
- ✅ Data classification policy and inventory
- ✅ Encryption key management procedures
- ✅ Backup and restore test results

**Audit Questions:**
1. How often is the asset inventory updated?
2. What is the average time to patch critical vulnerabilities?
3. How are emergency changes handled?
4. What network segmentation controls are in place?
5. How is data at rest encrypted?
6. What is the backup frequency for critical data?
7. When was the last restore test performed?

## Updates & Amendments

- **January 17, 2025**: RTS 2024/1772 application date
- **July 19, 2024**: Published in Official Journal
- **March 13, 2024**: Adopted by European Commission

## Related Standards & Frameworks

- **ISO 27001:2022**: Information Security Management Systems
- **ISO 27002:2022**: Information Security Controls
- **NIST Cybersecurity Framework**: Identify, Protect, Detect, Respond, Recover
- **CIS Controls**: Center for Internet Security Critical Security Controls
- **COBIT 2019**: Control Objectives for Information and Related Technologies

## References

- **Official Text**: https://eur-lex.europa.eu/eli/reg_del/2024/1772/oj
- **EBA Final Report**: https://www.eba.europa.eu/publications-and-media/press-releases/esas-publish-second-batch-policy-products-under-dora
- **DORA Main Regulation**: Regulation (EU) 2022/2554
