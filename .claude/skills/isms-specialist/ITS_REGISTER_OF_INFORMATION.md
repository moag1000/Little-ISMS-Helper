# DORA ITS - Register of Information on ICT Third-Party Arrangements

**Commission Implementing Regulation (EU) 2024/1689**

## Official Information

- **Full Title**: Commission Implementing Regulation (EU) 2024/1689 of 31 May 2024 laying down implementing technical standards for the application of Regulation (EU) 2022/2554 with regard to the standard templates for the register of information in relation to all contractual arrangements on the use of ICT services provided by ICT third-party service providers
- **Adopted**: May 31, 2024
- **Published**: Official Journal L 1689, June 21, 2024
- **Application Date**: January 17, 2025
- **Legal Basis**: DORA Article 28(9) (Register of information)
- **Official Text**: https://eur-lex.europa.eu/eli/reg_impl/2024/1689/oj

## Scope

Applies to all financial entities required to maintain a register of information under DORA Article 28(3), covering:
1. All contractual arrangements with ICT third-party service providers
2. Services supporting critical or important functions
3. Sub-contracting arrangements
4. Intragroup service providers

## Register Purpose

**Primary Objectives:**
- Provide competent authorities with complete visibility of ICT dependencies
- Enable concentration risk analysis (provider, service, geographic)
- Support oversight of critical ICT third-party providers (CTPPs)
- Facilitate financial stability assessments
- Enable cross-entity risk aggregation at sector level

**Not a Public Document:**
- Register is confidential
- Submitted to competent authority only
- Protected under professional secrecy obligations

## Template Structure

### Section 1: Financial Entity Information

**1.1 Entity Identification**
- Legal name
- LEI (Legal Entity Identifier) - mandatory if available
- National identifier (e.g., BaFin ID, FCA firm reference number)
- Registered address
- Head office location (if different)

**1.2 Entity Classification**
- Entity type: Credit institution, Investment firm, Payment institution, Insurance undertaking, Pension fund, Trading venue, Central counterparty, Other
- Size category: Large, Medium, Small (based on balance sheet total, turnover)
- Systemic importance: G-SII, O-SII, Significant institution (SSM), Other

**1.3 Contact Information**
- ICT risk management function contact (name, email, phone)
- Regulatory liaison contact
- Data protection officer (if applicable)

**1.4 Reporting Period**
- Register effective date
- Last update date
- Reporting frequency (annual minimum, or upon significant changes)

### Section 2: ICT Third-Party Service Provider Information

**For each ICT provider, include:**

**2.1 Provider Identification**
- Legal name
- LEI (if available)
- Registered address
- Head office location
- Country of incorporation
- Website

**2.2 Provider Classification**
- Type: Cloud service provider, Software vendor, Data center operator, Telecommunications, Managed security service provider, Payment service provider, Other
- **CTPP Status**: Yes / No (if yes, designating authority and date)
- Intragroup provider: Yes / No
- Size: Large, Medium, Small, Micro-enterprise

**2.3 Provider Scope**
- Services provided (detailed description)
- Number of contracts with financial entity
- Total annual contract value: €_________
- Contract duration: Start date / End date / Renewal date
- Service criticality: Critical / Important / Other

**2.4 Geographic Information**
- Data processing locations (country/region)
- Data storage locations (primary and backup)
- Support center locations
- Development center locations (if applicable)

**2.5 Certifications & Audits**
- ISO 27001: Yes / No (certificate number, validity)
- ISO 27017 (cloud security): Yes / No
- ISO 27018 (cloud privacy): Yes / No
- SOC 2 Type II: Yes / No (report date, auditor)
- Other certifications: PCI DSS, TISAX, etc.
- Last audit date by financial entity
- Next audit date (scheduled)

### Section 3: Services and Functions Supported

**3.1 Service Description**
- Service name
- Service type: Infrastructure (IaaS), Platform (PaaS), Software (SaaS), Managed service, Network, Security, Other
- Service category: Compute, Storage, Database, Analytics, Security, Communication, Backup/DR, Other

**3.2 Criticality Assessment**
- Function supported: Payment processing, Trading, Client access, Risk management, Accounting, Compliance, HR, Other
- **Critical or Important Function**: Yes / No
- Rationale for classification (brief)
- Risk assessment score (internal methodology)
- Last assessment date

**3.3 Data Processing**
- Personal data processed: Yes / No
  - If yes, categories: Client PII, Employee data, Transaction data, Authentication data, Special categories (GDPR Art. 9)
- Data volume: <10GB, 10GB-1TB, 1TB-10TB, >10TB
- Data sensitivity: Public, Internal, Confidential, Strictly confidential
- Cross-border data transfers: Yes / No
  - If yes, legal basis: Adequacy decision, Standard contractual clauses, BCRs, Derogations
  - Countries involved: ___________

**3.4 Service Dependencies**
- Other ICT services this service depends on (list)
- Single point of failure: Yes / No
- Alternative providers available: Yes / No / Partial
- Switching complexity: Low / Medium / High
- Estimated migration time: ___ months

### Section 4: Contractual Arrangements

**4.1 Contract Details**
- Contract number/reference
- Contract type: Master service agreement, Statement of work, SaaS subscription, License agreement, Other
- Contract date (signature)
- Contract duration: Fixed term / Indefinite
- Renewal: Automatic / Manual / Expires
- Notice period for termination: ___ days/months

**4.2 Service Level Agreements (SLAs)**
- Availability target: ___% (e.g., 99.9%)
- Maximum downtime per month: ___ hours
- Response time for critical incidents: ___ hours
- Resolution time: ___ hours/days
- Performance metrics: ___ (describe)
- Financial penalties for SLA breach: Yes / No

**4.3 Key Contractual Provisions**
- Audit rights: Yes / No
  - On-site inspection: Yes / No
  - Document review: Yes / No
  - Technical testing: Yes / No
- Sub-contracting: Allowed with notification / Requires approval / Prohibited
  - Notification period: ___ days
  - Objection rights: Yes / No
- Data protection: GDPR compliant / BCRs / SCCs / DPA signed
- Exit management:
  - Data return within ___ days
  - Transition assistance: Yes / No / For fee
  - Data deletion certified: Yes / No
- Liability cap: €_________
- Insurance: Professional indemnity €_________ / Cyber €_________

**4.4 Business Continuity**
- Provider BCP/DR in place: Yes / No / Unknown
- Last BCP test date: ___________
- RTO (Recovery Time Objective): ___ hours
- RPO (Recovery Point Objective): ___ hours
- Backup locations: ___________ (geographic)
- Failover tested: Yes / No / Date: ___________

### Section 5: Sub-Contracting Arrangements

**For each sub-contractor:**

**5.1 Sub-Contractor Identification**
- Legal name
- Country of incorporation
- Relationship: Direct sub-contractor / Sub-sub-contractor (specify layer)
- Service provided by sub-contractor
- Percentage of main service: ___% (e.g., 30% of cloud storage)

**5.2 Sub-Contractor Details**
- Data processing location
- Access to financial entity data: Yes / No
- Notification date (when financial entity informed)
- Financial entity approval: Required / Not required / Provided
- Certifications: ISO 27001 / SOC 2 / Other

**5.3 Sub-Contracting Chain**
- Total depth of sub-contracting chain: ___ layers
- Ultimate service provider (if different from primary ICT provider)
- Known sub-sub-contractors: ___ (list if >2 layers)

**5.4 Risk Assessment**
- Concentration risk: Same sub-contractor used by multiple ICT providers: Yes / No
- Geographic concentration: >50% in single country: Yes / No
- Critical dependency: Sub-contractor failure = service failure: Yes / No

### Section 6: Risk Assessment and Monitoring

**6.1 Initial Due Diligence**
- Due diligence date: ___________
- Due diligence performed by: Internal team / External consultant / Both
- Financial stability assessed: Yes / No
- Security posture assessed: Yes / No
- References checked: Yes / No
- Certifications verified: Yes / No

**6.2 Ongoing Monitoring**
- Monitoring frequency: Quarterly / Semi-annual / Annual
- Last review date: ___________
- Next review date: ___________
- KPIs tracked: Availability, Incidents, SLA compliance, Security events, Other
- Performance rating: Excellent / Good / Adequate / Poor

**6.3 Incident History**
- Number of incidents (last 12 months): ___
  - Critical: ___
  - High: ___
  - Medium: ___
  - Low: ___
- Major incidents impacting financial entity: ___ (describe)
- SLA breaches: ___ (number in last 12 months)
- Remediation actions taken: ___________ (summary)

**6.4 Risk Mitigation**
- Alternative providers identified: Yes / No
  - If yes, provider names: ___________
- Exit plan documented: Yes / No / In development
- Exit plan last tested: ___________ (date)
- Compensating controls: ___________ (list)

### Section 7: Concentration Risk Analysis

**7.1 Provider Concentration**
- Number of critical services from this provider: ___
- Percentage of total ICT budget to this provider: ___%
- Other financial entities in group using same provider: ___ (number)

**7.2 Geographic Concentration**
- Primary data location: ___________ (country)
- Percentage of services in single country: ___%
- High-risk jurisdictions involved: Yes / No
  - If yes, countries: ___________

**7.3 Service Concentration**
- Service type concentration: Cloud __%, SaaS __%, Managed services __%, Other ___%
- Single technology platform: Yes / No (e.g., all on AWS)
- Dependency on provider's proprietary technologies: Yes / No

**7.4 Cross-Entity Concentration (for conglomerates)**
- Number of entities in group using same provider: ___
- Aggregated annual contract value: €_________
- Systemic risk assessment: Low / Medium / High

### Section 8: Exit Strategy

**8.1 Exit Planning**
- Exit strategy documented: Yes / No / Partial
- Exit triggers defined: Yes / No
  - Triggers: SLA breaches, Security incidents, Financial instability, Regulatory changes, Contract expiry, Other
- Exit plan last updated: ___________ (date)

**8.2 Migration Feasibility**
- Estimated migration duration: ___ months
- Estimated migration cost: €_________
- Technical complexity: Low / Medium / High
- Data portability: Straightforward / Complex / Very complex
- Service interruption expected: Yes / No
  - If yes, estimated downtime: ___ hours/days

**8.3 Alternative Providers**
- Alternative provider 1: ___________ (name)
  - Service compatibility: Full / Partial / Limited
  - Cost comparison: Lower / Similar / Higher
  - Migration timeframe: ___ months
- Alternative provider 2: ___________ (if applicable)
- In-house alternative: Feasible / Not feasible

### Section 9: Regulatory and Compliance

**9.1 Regulatory Notifications**
- Provider notified to competent authority: Yes / No
  - Notification date: ___________
- CTPP oversight aware: Yes / No / N/A
- Cross-border notifications (other EU authorities): Yes / No

**9.2 Compliance Obligations**
- GDPR compliance: DPA signed / BCRs / SCCs / Adequacy decision
- Data protection authority informed (if required): Yes / No / N/A
- Sector-specific regulations: MiFID II, PSD2, Solvency II, CRR, Other
- Specific compliance requirements: ___________ (describe)

**9.3 Audit and Inspection**
- Last audit by financial entity: ___________ (date)
- Last audit by competent authority: ___________ (date)
- Last third-party audit (ISO, SOC): ___________ (date)
- Audit findings: None / Minor / Moderate / Significant
- Remediation status: Complete / In progress / Planned

### Section 10: Changes and Updates

**10.1 Change Management**
- Last material change: ___________ (date)
- Type of change: New service, Service expansion, Provider change, Contract renewal, Other
- Change approval: Management board / ICT committee / Risk function / Other
- Change notification to authority: Yes / No / Not required

**10.2 Update History**
- Register version: ___ (e.g., 1.0, 1.1, 2.0)
- Last full review date: ___________
- Next scheduled update: ___________
- Changes since last submission: ___________ (summary)

## Submission Requirements

### Timeline

**Initial Submission:**
- **By January 17, 2026** (one year after DORA application date)
- Covers all existing ICT arrangements as of January 17, 2025

**Updates:**
- **Annual submission**: By January 31 each year
- **Ad-hoc updates**: Within 10 business days of material changes
  - Material changes: New critical provider, Service criticality change, CTPP designation, Major incidents, Contract termination

### Submission Format

**Accepted Formats:**
1. **XML file** (preferred)
   - Schema provided by competent authority
   - Structured data fields
   - Automated validation

2. **Excel template** (alternative)
   - Standardized template with tabs per section
   - Drop-down menus for consistency
   - Formula validation

3. **Online portal** (where available)
   - Web form with auto-save
   - Real-time validation
   - Digital signature

**File Naming Convention:**
`DORA_Register_[LEI]_[YYYY-MM-DD].xml`

Example: `DORA_Register_529900HNOAA1KXQJUQ27_2025-01-31.xml`

### Data Quality Requirements

**Mandatory Fields:**
- All Section 1 fields (financial entity identification)
- All Section 2.1-2.2 fields (provider identification and classification)
- All Section 3.2 fields (criticality assessment)
- Section 4.3 key contractual provisions (audit, sub-contracting, exit)
- Section 5 (if sub-contractors exist)

**Optional but Recommended:**
- Section 6.3 (incident history) - demonstrates ongoing monitoring
- Section 7 (concentration risk analysis) - proactive risk management
- Section 8 (exit strategy) - business continuity preparedness

### Data Validation

**Automated Checks:**
- LEI format validation (20-character alphanumeric)
- Date format (ISO 8601: YYYY-MM-DD)
- Country codes (ISO 3166-1 alpha-2)
- Currency codes (ISO 4217)
- Email format
- Percentage fields (0-100%)
- Numeric fields (non-negative)

**Consistency Checks:**
- Contract end date > start date
- Next review date > last review date
- RTO/RPO values realistic
- Sub-contractor notifications before service start
- CTPP designation dates after November 18, 2025

## Integration with Application

### Data Sources

**Existing Entities:**
- `ICTProvider` - Maps to Section 2
- `ICTContract` - Maps to Section 4
- `ICTService` - Maps to Section 3
- `BusinessProcess` - Criticality assessment (Section 3.2)
- `Tenant` - Financial entity information (Section 1)

**New Fields Needed:**
```php
// ICTProvider entity enhancements
private ?string $lei = null;
private ?string $ctppStatus = null; // 'designated', 'not_designated'
private ?DateTime $ctppDesignationDate = null;
private array $dataProcessingLocations = [];
private array $certifications = []; // ISO 27001, SOC 2, etc.

// ICTContract entity enhancements
private ?int $availabilitySlaPercent = null; // e.g., 99.9
private ?int $maxDowntimeHoursPerMonth = null;
private ?int $responseTimeHoursCritical = null;
private ?string $subcontractingPolicy = null; // 'notification', 'approval', 'prohibited'
private ?int $notificationPeriodDays = null;
private ?string $liabilityCap = null;
private ?string $insuranceProfessionalIndemnity = null;
private ?string $insuranceCyber = null;
```

### Automation Strategy

**Data Collection:**
1. **Manual entry** for initial setup (via forms in application)
2. **Automated aggregation** from existing entities
3. **API integration** where ICT providers provide structured data
4. **Regular review workflows** (reminders for annual updates)

**Report Generation:**
```php
// src/Service/DORARegisterService.php
public function generateRegister(Tenant $tenant, DateTime $asOf): array
{
    return [
        'section1' => $this->getEntityInformation($tenant),
        'section2' => $this->getProvidersInformation($tenant),
        'section3' => $this->getServicesAndFunctions($tenant),
        'section4' => $this->getContractualArrangements($tenant),
        'section5' => $this->getSubcontracting($tenant),
        'section6' => $this->getRiskAssessment($tenant),
        'section7' => $this->getConcentrationRisk($tenant),
        'section8' => $this->getExitStrategy($tenant),
        'section9' => $this->getRegulatoryCompliance($tenant),
        'section10' => $this->getChangeHistory($tenant, $asOf),
    ];
}

public function exportToXml(array $registerData): string
{
    // Generate XML per competent authority schema
    // Validate against XSD
    // Return XML string for submission
}

public function exportToExcel(array $registerData): string
{
    // Use PhpSpreadsheet to generate Excel file
    // Multiple tabs per section
    // Return file path
}
```

### User Interface

**Register Management Screen:**
```
/compliance/dora/register

Sections:
1. Overview (summary dashboard)
   - Total ICT providers: 47
   - Critical providers: 12
   - CTPPs: 3
   - Last updated: 2025-11-15
   - Next submission: 2026-01-31

2. ICT Providers (list view with filters)
   - Filter by: Criticality, CTPP status, Service type
   - Export selected to register

3. Concentration Risk Dashboard
   - Provider concentration chart
   - Geographic concentration map
   - Service type breakdown

4. Register Export
   - Select reporting period
   - Generate XML / Excel / PDF
   - Submit to authority (API integration)

5. Change Log
   - Material changes tracker
   - Notification status
```

## ISO 27001:2022 Integration

| Register Requirement | ISO 27001 Control | Notes |
|----------------------|-------------------|-------|
| ICT provider inventory | A.5.19 (Supplier relationships) | Enhanced with DORA-specific fields |
| Due diligence | A.5.20 (Addressing IS in supplier agreements) | Initial and ongoing assessment |
| Contractual provisions | A.5.21 (Managing IS in supplier relationships) | DORA mandatory clauses |
| Service monitoring | A.5.22 (Supplier service delivery) | KPIs and incident tracking |
| Concentration risk | A.8.30 (Outsourcing) | DORA-specific analysis |
| Exit strategy | A.5.23 (ICT for BC in supplier relationships) | Exit planning and testing |
| Data location | A.5.14 (Information transfer) | Cross-border tracking |
| Sub-contractor management | A.5.19 (extended) | Multi-layer visibility |

**ISO 27001 Gap:**
- ISO 27001 A.5.19-5.23 cover supplier management, but not with DORA's level of detail
- Register requires quantitative metrics (SLA %, contract values, incident counts)
- CTPP designation tracking is DORA-specific
- Concentration risk analysis more granular than ISO 27001

**ISO 27001 + DORA Together:**
- ISO 27001 provides the supplier management framework
- DORA register adds financial sector-specific requirements
- Use ISO 27001 supplier reviews to populate register data
- Leverage register data for ISO 27001 supplier risk assessments

## Implementation Checklist

### Preparation Phase (Q4 2024 - Q1 2025)
- [ ] Inventory all ICT third-party arrangements (existing and new)
- [ ] Collect missing data (LEI, certifications, data locations)
- [ ] Define criticality assessment criteria
- [ ] Establish data collection processes
- [ ] Designate register owner (CISO, CRO, Compliance Officer)

### System Setup (Q1 2025)
- [ ] Enhance application entities (ICTProvider, ICTContract)
- [ ] Build register management interface
- [ ] Implement XML/Excel export functionality
- [ ] Develop concentration risk analytics
- [ ] Create change notification workflows

### Data Population (Q1 2025 - Q4 2025)
- [ ] Populate Section 1 (entity information)
- [ ] Populate Section 2 (provider information) for all providers
- [ ] Populate Section 3 (services) and assess criticality
- [ ] Populate Section 4 (contracts) - review all agreements
- [ ] Populate Section 5 (sub-contractors) - engage providers for info
- [ ] Populate Section 6 (risk assessments) - conduct reviews
- [ ] Populate Section 7 (concentration risk) - analyze dependencies
- [ ] Populate Section 8 (exit strategies) - document plans
- [ ] Populate Section 9 (regulatory compliance) - validate
- [ ] Populate Section 10 (change log) - establish baseline

### Quality Assurance (Q4 2025)
- [ ] Data validation (completeness, accuracy)
- [ ] Internal audit of register
- [ ] Management review and approval
- [ ] Legal review of confidentiality
- [ ] IT security review (access controls)

### Submission (January 2026)
- [ ] Generate final register (XML/Excel)
- [ ] Digital signature (if required)
- [ ] Submit to competent authority by January 17, 2026
- [ ] Confirm receipt
- [ ] Address any authority queries

### Ongoing Maintenance (2026+)
- [ ] Quarterly register reviews
- [ ] Material change notifications (within 10 business days)
- [ ] Annual full submission (by January 31 each year)
- [ ] Continuous improvement (based on authority feedback)

## Common Scenarios

### Scenario 1: Cloud Provider with Multiple Services

**Entity:** Medium-sized payment institution
**Provider:** AWS
**Services:** EC2, S3, RDS, Lambda, CloudWatch (5 services)

**Register Entry:**
- **Section 2**: AWS identified as CTPP (designated November 18, 2025)
- **Section 3**: Each service separately listed
  - EC2 supports payment processing → Critical
  - S3 supports document storage → Important
  - RDS supports transaction database → Critical
  - Lambda supports API gateway → Critical
  - CloudWatch supports monitoring → Important
- **Section 4**: Single Master Agreement with multiple SoWs
- **Section 5**: AWS sub-contractors: Data centers (Equinix), Network (Level 3)
- **Section 7**: Concentration risk HIGH (60% of critical services with AWS)
- **Section 8**: Exit strategy: Migration to Azure documented, 18-month timeframe

### Scenario 2: Intragroup ICT Provider

**Entity:** Subsidiary insurance company
**Provider:** Parent company's IT department (intragroup)

**Register Entry:**
- **Section 2**: Intragroup provider = Yes
- **Section 3**: Core insurance platform support → Critical
- **Section 4**: Service Level Agreement (not commercial contract)
  - Audit rights: Yes (internal audit)
  - Exit management: N/A (intragroup)
- **Section 5**: Parent company uses external sub-contractors (SAP, Microsoft)
  - Register must include these sub-contractors
- **Section 7**: Concentration risk MEDIUM (single provider, but internal control)
- **Section 9**: No GDPR DPA required (intragroup), but data transfer policies apply

### Scenario 3: Multiple Small Providers

**Entity:** Small investment firm
**Provider:** 15 different SaaS providers (email, CRM, accounting, portfolio management, etc.)

**Challenge:** Register complexity with many entries

**Approach:**
1. **Prioritize critical/important functions**
   - Portfolio management system → Critical
   - Client communication platform → Important
   - Others → Register but less detail
2. **Use standardized templates** for similar providers (all SaaS)
3. **Leverage provider self-attestations** for certifications, sub-contractors
4. **Focus exit strategies** on critical providers only

**Register Entry:**
- **15 separate Section 2-4 entries** (one per provider)
- **Section 7 concentration analysis**: No single provider concentration, but SaaS dependency HIGH
- **Section 8 exit strategies**: Documented for 2 critical providers, high-level for others

## Penalties for Non-Compliance

**Failure to Maintain Register:**
- Breach of DORA Article 28(3)
- Penalties up to **2% of global annual turnover** or **€10 million** (whichever higher)

**Incomplete or Inaccurate Register:**
- Proportionate penalties based on severity
- Resubmission requirements
- Enhanced supervisory measures

**Late Submission:**
- Initial submission deadline: **January 17, 2026**
- Late submission = administrative penalties
- Aggravating factor in supervisory assessments

**Failure to Update:**
- Material changes must be notified within **10 business days**
- Annual updates mandatory by **January 31** each year
- Non-compliance = ongoing breach

## Best Practices

**For Financial Entities:**

1. **Designate Register Owner**: CISO or CRO with dedicated team (not one person's side task)

2. **Automate Data Collection**: Integrate with existing GRC tools, contract management systems, supplier databases

3. **Engage ICT Providers Early**: Request data from providers in advance (they need time to gather sub-contractor info)

4. **Use Structured Templates**: Provide providers with standardized questionnaires (reduces inconsistency)

5. **Regular Reviews**: Quarterly reviews, not just annual updates (catch changes early)

6. **Concentration Risk Monitoring**: Dashboard with real-time metrics (% per provider, geographic distribution)

7. **Version Control**: Maintain audit trail of register changes (who, what, when)

8. **Test Export Process**: Don't wait until January 2026 deadline to test XML/Excel export (validate schema compliance)

9. **Legal Review**: Ensure contractual audit rights support register information collection

10. **Training**: Train procurement, IT, compliance teams on register requirements (new contracts must capture required data)

## Resources

- **Official Text**: https://eur-lex.europa.eu/eli/reg_impl/2024/1689/oj
- **DORA Article 28**: Main regulation on ICT third-party arrangements
- **EBA Register Guidance**: https://www.eba.europa.eu/regulation-and-policy/operational-resilience/register-of-information-under-dora
- **Competent Authority Portals**: Country-specific submission instructions
- **XML Schema**: Request from competent authority (BaFin, ECB, etc.)

## Data Reuse Opportunities

**Within Application:**

1. **Risk Register ↔ ICT Register**
   - ICT provider risk assessments feed risk register
   - Risk treatment plans link to exit strategies

2. **Asset Management ↔ ICT Register**
   - ICT services are ICT assets
   - Asset dependencies map to provider dependencies

3. **Incident Management ↔ ICT Register**
   - Provider incidents automatically update Section 6.3
   - Incident root causes inform concentration risk analysis

4. **Audit Management ↔ ICT Register**
   - Audit findings update Section 9.3
   - Register data feeds audit risk assessments

5. **Business Continuity ↔ ICT Register**
   - Provider RTOs/RPOs feed BCM planning
   - BCM exercises test provider failover (update Section 4.4)

6. **Compliance Dashboard ↔ ICT Register**
   - Register completeness KPI
   - Concentration risk alerts
   - Material change notifications

**Cross-Framework Reuse:**

| Data Field | DORA Register | ISO 27001 | NIS2 | GDPR |
|------------|---------------|-----------|------|------|
| Provider list | Section 2 | A.5.19 supplier list | Art. 21(2)(i) supply chain | Art. 28 processor list |
| Contracts | Section 4 | A.5.20 agreements | NIS2 supplier agreements | Art. 28(3) DPAs |
| Risk assessments | Section 6 | A.5.19 supplier risk | Art. 21(2)(a) risk analysis | Art. 35 DPIA |
| Data locations | Section 3.3 | A.5.14 transfer controls | Art. 21(2)(e) data protection | Art. 44-49 transfers |
| Incident history | Section 6.3 | A.5.26 incident response | Art. 23 notifications | Art. 33 breach notifications |

## Updates Log

- **January 17, 2026**: First submission deadline (one year after DORA application)
- **January 17, 2025**: ITS 2024/1689 application date
- **June 21, 2024**: Published in Official Journal
- **May 31, 2024**: Adopted by European Commission

## Workflow Integration

### New ICT Provider Onboarding

```
1. Procurement initiates RFP
   ↓
2. Due diligence (Section 6.1 data collected)
   ↓
3. Contract negotiation (Section 4 provisions)
   ↓
4. Management approval
   ↓
5. **Register update** (Sections 2-4 populated)
   ↓
6. Authority notification (if material, within 10 days)
   ↓
7. Ongoing monitoring (Section 6.2 KPIs tracked)
```

### Material Change Workflow

```
Trigger: Provider notifies sub-contractor change
   ↓
1. ICT team reviews notification (Section 5)
   ↓
2. Risk assessment update (Section 6.4)
   ↓
3. Concentration risk re-analysis (Section 7)
   ↓
4. Management informed (if significant)
   ↓
5. **Register updated** (Section 5 + Section 10)
   ↓
6. Authority notification (within 10 business days)
```

### Annual Register Review

```
November: Review planning
   ↓
December: Data collection from providers
   ↓
January 1-15: Data validation and quality checks
   ↓
January 15-25: Management review and approval
   ↓
January 26-30: Final export and submission
   ↓
January 31: Submission to competent authority
```

This completes the DORA ITS Register of Information reference documentation.