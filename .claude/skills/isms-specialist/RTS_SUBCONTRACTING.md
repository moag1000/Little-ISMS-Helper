# DORA RTS - Subcontracting of ICT Services

**Commission Delegated Regulation (EU) 2024/1932**

## Official Information

- **Full Title**: Commission Delegated Regulation (EU) 2024/1932 of 12 June 2024 supplementing Regulation (EU) 2022/2554 with regard to regulatory technical standards specifying the elements that a financial entity must determine when subcontracting ICT services supporting critical or important functions
- **Adopted**: June 12, 2024
- **Published**: Official Journal L 1932, July 23, 2024
- **Application Date**: January 17, 2025
- **Legal Basis**: DORA Article 30(5) (ICT third-party service provider subcontracting)
- **Official Text**: https://eur-lex.europa.eu/eli/reg_del/2024/1932/oj

## Scope

Applies to:
- **Financial entities** using ICT third-party providers
- **ICT third-party providers** (including CTPPs) that subcontract services
- Covers subcontracting of services supporting **critical or important functions**

## Key Definitions

**Subcontracting**: ICT third-party provider engages another entity (sub-contractor) to perform part or all of the ICT services contracted by the financial entity.

**Sub-contractor**: Entity engaged by ICT third-party provider to deliver ICT services.

**Chain of Subcontracting**: Multiple layers of sub-contractors (e.g., Provider → Sub-contractor → Sub-sub-contractor).

## Contractual Requirements (Article 3-6)

### Article 3: Subcontracting Notification Rights

**Financial Entity Must Ensure Contract Includes:**

1. **Prior Notification**
   - ICT provider must inform financial entity **before** engaging sub-contractor
   - **Minimum 30 calendar days** advance notice
   - For critical services: **60 calendar days** advance notice

2. **Notification Content**
   - Sub-contractor identity and location(s)
   - Service description (what will be subcontracted)
   - Sub-contractor's role (partial vs. full service)
   - Rationale for subcontracting
   - Risk assessment summary

3. **Objection Rights**
   - Financial entity may object within notification period
   - Grounds: Risk concerns, regulatory requirements, policy violations
   - Provider must address objections or not proceed

### Article 4: Sub-Contractor Information Requirements

**Minimum Information Financial Entity Must Obtain:**

1. **Sub-Contractor Profile**
   - Legal name and registration
   - Geographic locations (data centers, support centers)
   - Relevant certifications (ISO 27001, SOC 2, etc.)
   - Financial stability indicators
   - Other clients in financial sector (concentration risk)

2. **Service Details**
   - Specific services subcontracted
   - Percentage of overall service (e.g., 30% of cloud storage)
   - Data processing locations
   - Access to financial entity data (yes/no, scope)

3. **Security & Compliance**
   - Security controls in place
   - Compliance with applicable regulations (GDPR, etc.)
   - Audit rights
   - Incident notification procedures

4. **Subcontracting Chain**
   - Sub-sub-contractors (if any)
   - Chain depth (number of layers)
   - Ultimate service delivery entity

### Article 5: Contractual Provisions for Sub-Contractors

**ICT Provider's Contract with Sub-Contractor Must Include:**

1. **Flow-Down Clauses**
   - Security requirements from main contract flow to sub-contractor
   - Compliance obligations (DORA, GDPR, etc.)
   - Audit rights extend to sub-contractor
   - Incident notification to financial entity

2. **Data Protection**
   - GDPR compliance (if personal data processed)
   - Data location restrictions
   - Data retention and deletion
   - Cross-border transfer safeguards

3. **Access & Audit**
   - Financial entity's right to audit sub-contractor (directly or via ICT provider)
   - Competent authority access rights
   - Right to inspect premises
   - Documentation access

4. **Exit Management**
   - Termination procedures
   - Data return/deletion
   - Service transition assistance
   - Continuity obligations during transition

5. **Liability & Insurance**
   - Sub-contractor liability for failures
   - Professional indemnity insurance
   - Cyber insurance (minimum coverage levels)

### Article 6: Monitoring Subcontracting Arrangements

**Financial Entity Obligations:**

1. **Register Maintenance**
   - Maintain up-to-date register of all sub-contractors
   - Update within 10 business days of changes
   - Include in overall ICT register (ITS 2024/1689)

2. **Risk Assessment**
   - Annual risk assessment of subcontracting arrangements
   - Concentration risk analysis (multiple services from same sub-contractor)
   - Geographic risk (data centers in high-risk countries)
   - Chain risk (depth of subcontracting layers)

3. **Performance Monitoring**
   - Sub-contractor incidents tracked
   - SLA compliance (if sub-contractor impacts main service)
   - Quarterly review of subcontracting landscape

4. **Escalation**
   - Defined triggers for re-evaluation (incidents, audit findings, contract changes)
   - Escalation to management/board
   - Potential service termination criteria

## Subcontracting Approval Process

### Step-by-Step Process

**Step 1: ICT Provider Identifies Need to Subcontract**
- Business case (cost reduction, specialized expertise, capacity)
- Sub-contractor selection (RFP or direct selection)

**Step 2: Due Diligence on Sub-Contractor**
- Financial stability check
- Security posture assessment
- Reference checks
- Certification verification (ISO 27001, SOC 2)

**Step 3: Notification to Financial Entity** (30-60 days advance)
- Formal notification with required information (Article 4)
- Risk assessment documentation
- Draft contract terms

**Step 4: Financial Entity Review** (within notification period)
- Risk assessment
- Compliance check (policies, regulations)
- Concentration risk analysis
- Decision: Approve, Conditional approval, or Object

**Step 5: Objection Handling** (if applicable)
- Financial entity raises concerns
- ICT provider addresses (additional controls, contract amendments)
- Re-submission or cancellation

**Step 6: Contract Execution**
- ICT provider signs contract with sub-contractor
- Flow-down clauses implemented
- Financial entity informed of go-live date

**Step 7: Ongoing Monitoring**
- Quarterly performance reviews
- Annual risk re-assessment
- Incident tracking

## Concentration Risk Management

### Identifying Concentration Risk

**Single Sub-Contractor Concentration:**
- Same sub-contractor supports >30% of critical services
- Same sub-contractor used by multiple ICT providers (indirect concentration)

**Geographic Concentration:**
- >50% of services in single country/region
- High-risk jurisdictions (political instability, weak data protection)

**Technology Concentration:**
- Single technology platform (e.g., all on AWS)
- Vendor lock-in (proprietary technologies)

### Mitigation Strategies

1. **Diversification**
   - Multi-cloud strategy (AWS + Azure + GCP)
   - Geographic distribution (EU + non-EU data centers)
   - Multiple ICT providers for similar services

2. **Contractual Safeguards**
   - Interoperability requirements (avoid lock-in)
   - Data portability clauses
   - Transition assistance obligations

3. **Exit Planning**
   - Documented migration plan to alternative sub-contractor
   - Regular testing of migration (tabletop exercise annually)
   - Relationship with alternative providers maintained

4. **Monitoring**
   - Quarterly concentration risk metrics
   - Board reporting (annual)
   - Trigger levels for action (e.g., >40% = mandatory diversification plan)

## Subcontracting Chain Management

### Mapping the Chain

**Example Chain:**
```
Financial Entity
    ↓ (contract)
ICT Provider (e.g., Cloud Provider)
    ↓ (subcontract)
Sub-Contractor 1 (e.g., Data Center Operator)
    ↓ (sub-subcontract)
Sub-Contractor 2 (e.g., Cooling System Provider)
```

**Financial Entity Obligations:**
- **Visibility**: Know all layers of chain (minimum 2 layers down)
- **Accountability**: ICT provider remains responsible for entire chain
- **Audit Rights**: Extend to all layers
- **Risk Assessment**: Assess risks at each layer

### Chain Depth Limits

**Best Practice:**
- **Maximum 2-3 layers** for critical services
- **Contractual limit**: Require approval for layers beyond 2

**Rationale:**
- Each layer increases complexity
- Reduced control and visibility
- Incident response delays
- Data protection compliance harder to ensure

## ISO 27001 Integration

| Subcontracting Requirement | ISO 27001 Control | Notes |
|----------------------------|-------------------|-------|
| Supplier assessment | A.5.19 (Supplier relationships) | Enhanced for sub-contractors |
| Contract requirements | A.5.21 (Managing IS in supplier relationships) | DORA flow-down clauses |
| Monitoring | A.5.22 (Supplier service delivery) | Sub-contractor KPIs |
| Information security in supplier agreements | A.5.20 (Addressing IS in agreements) | DORA-specific terms |

**Gap:**
- ISO 27001 addresses direct suppliers, DORA requires sub-contractor visibility
- Enhanced due diligence and contractual terms needed

## Common Subcontracting Scenarios

### Scenario 1: Cloud Provider Subcontracting Data Center

**Setup:**
- Financial entity: Bank
- ICT provider: AWS
- Sub-contractor: Equinix (data center operator)

**Notification:**
- AWS notifies bank 30 days before moving data to Equinix data center
- Bank reviews: Equinix ISO 27001 certified, SOC 2 Type II, EU location → Approved

**Contractual:**
- AWS contract with Equinix includes flow-down: Bank's audit rights, GDPR compliance, incident notification

**Monitoring:**
- Bank includes Equinix in ICT register
- Annual risk assessment: Low risk (established provider, EU location)

### Scenario 2: Software Provider Subcontracting Development

**Setup:**
- Financial entity: Insurance company
- ICT provider: Core insurance platform vendor
- Sub-contractor: Offshore development team (India)

**Notification:**
- Vendor notifies 60 days (critical system)
- Insurance company concerns: Data access, IP protection, GDPR compliance

**Objection:**
- Insurance company objects: Development team will access production-like data
- Vendor response: Data anonymization, EU-based project management, SOC 2 audit

**Conditional Approval:**
- Approved with conditions: No production data access, annual audits, EU-based oversight

### Scenario 3: Multiple Sub-Contractor Layers

**Setup:**
- Financial entity: Asset manager
- ICT provider: SaaS platform
- Sub-contractor 1: Cloud infrastructure (Azure)
- Sub-contractor 2: CDN provider (Cloudflare)
- Sub-contractor 3: Backup service (Veeam)

**Chain Management:**
- SaaS provider notifies asset manager of all 3 sub-contractors
- Asset manager maps full chain
- Risk assessment: Concentration risk (all with same SaaS provider)
- Mitigation: Request diversification plan, maintain alternative SaaS provider relationship

## Penalties for Non-Compliance

**Financial Entity Non-Compliance:**
- Inadequate subcontracting oversight
- Missing contractual clauses
- **Penalty**: Up to 2% of global turnover (DORA Article 50)

**ICT Provider Non-Compliance:**
- Failure to notify financial entity
- Subcontracting without approval
- **Penalty**: Contract breach, termination rights, damages

**CTPP Non-Compliance:**
- Failure to notify Lead Overseer of sub-contractors
- **Penalty**: Up to €5M or 1% of turnover (RTS 2024/1859)

## Implementation Checklist

### For Financial Entities

**Contract Review (by January 17, 2025):**
- [ ] Review all ICT contracts for subcontracting clauses
- [ ] Add notification rights (30-60 day advance notice)
- [ ] Add objection rights
- [ ] Add sub-contractor information requirements
- [ ] Add audit rights to sub-contractors
- [ ] Add flow-down clause obligations

**Processes:**
- [ ] Define subcontracting approval workflow
- [ ] Designate approval authority (CISO, CRO, Board)
- [ ] Create sub-contractor risk assessment template
- [ ] Implement sub-contractor register
- [ ] Define concentration risk thresholds

**Governance:**
- [ ] Board policy on subcontracting limits
- [ ] Annual reporting on subcontracting landscape
- [ ] Quarterly risk metrics

### For ICT Providers

**Contract Updates:**
- [ ] Update client contracts (notification, objection rights)
- [ ] Update sub-contractor templates (flow-down clauses)
- [ ] Ensure audit rights extend to sub-contractors

**Processes:**
- [ ] Implement notification procedure (30-60 days)
- [ ] Due diligence checklist for sub-contractors
- [ ] Sub-contractor register for all financial entity clients

**Communication:**
- [ ] Notify all financial entity clients of existing sub-contractors
- [ ] Establish notification templates
- [ ] Train account teams on DORA requirements

## Best Practices

**For Financial Entities:**
1. Proactive engagement: Request sub-contractor list annually (even without changes)
2. Standardized templates: Pre-approved sub-contractor risk assessment questionnaire
3. Categorization: Tiered approach (critical/important/other) with different approval levels
4. Automation: Sub-contractor register integrated with GRC tools
5. Collaboration: Share sub-contractor risk assessments across industry (anonymized)

**For ICT Providers:**
1. Early notification: Notify 90 days (beyond minimum) for smooth approval
2. Pre-approval: Maintain pre-approved sub-contractor list with clients
3. Transparency: Proactive disclosure of subcontracting chain
4. Evidence: Provide sub-contractor audit reports, certifications
5. Alternatives: Offer alternative sub-contractors (client choice)

## Resources

- **Official Text**: https://eur-lex.europa.eu/eli/reg_del/2024/1932/oj
- **DORA Article 30**: Main regulation on ICT third-party contracts
- **ITS 2024/1689**: Register of information (includes sub-contractors)
- **Cloud Industry Forum**: Subcontracting guidance for financial services

## Updates Log

- **January 17, 2025**: RTS 2024/1932 application date
- **July 23, 2024**: Published in Official Journal
- **June 12, 2024**: Adopted by European Commission
