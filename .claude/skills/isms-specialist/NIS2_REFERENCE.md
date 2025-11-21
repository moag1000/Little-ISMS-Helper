# NIS2 Directive - Complete Reference Document

**Directive (EU) 2022/2555** - Network and Information Security Directive 2

## Official Information

- **Full Title**: Directive (EU) 2022/2555 of the European Parliament and of the Council of 14 December 2022 on measures for a high common level of cybersecurity across the Union
- **Adopted**: December 14, 2022
- **Published**: Official Journal L 333, December 27, 2022
- **Entry into Force**: January 16, 2023
- **Transposition Deadline**: October 17, 2024 (Member States)
- **Application**: October 18, 2024 (entities - with 21-month grace period from transposition)
- **Official Text**: https://eur-lex.europa.eu/eli/dir/2022/2555/oj
- **Replaces**: Directive (EU) 2016/1148 (NIS1)

## German Implementation

### NIS2UmsuCG (NIS2-Umsetzungs- und Cybersicherheitsstärkungsgesetz)

- **Status (November 2025)**: ✅ **Adopted by Bundestag on November 13, 2025**
- **Cabinet Adoption**: July 30, 2025
- **First Reading**: Autumn 2025
- **Bundestag Adoption**: November 13, 2025
- **Entry into Force**: Before end of 2025 (law enters into force day after promulgation)
- **Impact**: Approximately **29,000 companies** will be obliged to implement cybersecurity measures
- **No Transition Period**: Obligations apply immediately from law's entry into force
- **Competent Authority**: BSI (Bundesamt für Sicherheit in der Informationstechnik)
- **Sectoral Authorities**: BaFin (finance), BNetzA (energy/telecom), others per sector

### Legislative History
- **October 2024**: Original implementation deadline missed
- **February 2025**: Early Federal elections
- **Discontinuity Principle**: Draft bill had to be reintroduced and renegotiated
- **July 30, 2025**: Federal Cabinet adopted new bill
- **November 13, 2025**: Bundestag adopted NIS2UmsuCG

## Scope of Application

### Entity Categories (Article 3)

**Essential Entities** (Annex I):
1. Energy (electricity, district heating/cooling, oil, gas, hydrogen)
2. Transport (air, rail, water, road)
3. Banking
4. Financial market infrastructures
5. Health sector
6. Drinking water
7. Waste water
8. Digital infrastructure
9. ICT service management (B2B)
10. Public administration
11. Space

**Important Entities** (Annex II):
1. Postal and courier services
2. Waste management
3. Manufacture, production and distribution of chemicals
4. Food production, processing and distribution
5. Manufacturing (medical devices, electronics, machinery, motor vehicles, etc.)
6. Digital providers (online marketplaces, search engines, social networks)
7. Research organisations

### Size Thresholds (Article 2(2))

Entities are in scope if they are:
- **Medium-sized**: ≥50 employees OR ≥€10 million turnover/balance sheet
- **Large**: ≥250 employees OR ≥€50 million turnover AND ≥€43 million balance sheet

**Exceptions**:
- Microenterprises and small enterprises generally excluded (Article 2(2))
- Unless entity is sole provider of service in Member State
- Unless entity is critical for maintaining critical societal/economic activities

### Out of Scope
- Armed forces, police, intelligence services
- Judicial authorities
- Parliaments, central banks (in specific functions)

## Key Requirements

### Article 21: Cybersecurity Risk Management Measures

All essential and important entities must implement appropriate and proportionate technical, operational and organisational measures.

#### Article 21(2) - Technical & Organizational Measures

**(a) Policies on Risk Analysis and Information System Security**
- Risk analysis
- Information security policies
- **ISO 27001 Mapping**: Clause 6.1 (Risk assessment), A.5.1 (Policy)

**(b) Incident Handling**
- Detection mechanisms
- Response procedures
- Recovery procedures
- Crisis management
- **ISO 27001 Mapping**: A.5.24-A.5.28 (Incident management)

**(c) Business Continuity, Backup Management and Disaster Recovery, and Crisis Management**
- Business continuity plans
- Backup procedures
- Disaster recovery plans
- Crisis management procedures
- **ISO 27001 Mapping**: A.5.29, A.5.30 (→ refer to BCM specialist)

**(d) Supply Chain Security**
- Security-related aspects of relationships with suppliers
- Security measures for supply chain
- **ISO 27001 Mapping**: A.5.19-A.5.23 (Supplier relationships)

**(e) Security in Network and Information Systems Acquisition, Development and Maintenance**
- Secure development lifecycle
- Procurement security requirements
- Maintenance procedures
- **ISO 27001 Mapping**: A.8.9 (Configuration management), A.8.25-A.8.34 (Secure development)

**(f) Policies and Procedures to Assess the Effectiveness of Cybersecurity Risk-Management Measures**
- Regular assessment
- Effectiveness evaluation
- Continuous improvement
- **ISO 27001 Mapping**: A.5.15-A.5.18, A.8.2-A.8.5 (Access control)

**(g) Basic Cyber Hygiene Practices and Cybersecurity Training**
- Regular training
- Awareness programs
- Basic security practices
- **ISO 27001 Mapping**: A.6.3 (Awareness, education, training)

**(h) Policies and Procedures Regarding the Use of Cryptography and Encryption**
- Cryptography policy
- Encryption standards
- Key management
- **ISO 27001 Mapping**: A.8.24 (Use of cryptography)

**(i) Human Resources Security, Access Control Policies and Asset Management**
- Personnel security
- Access control
- Asset inventory
- Privileged access management
- Multi-factor authentication
- **ISO 27001 Mapping**:
  - A.6.1-A.6.8 (People controls)
  - A.8.2-A.8.5 (Access control)
  - A.5.9, A.5.10 (Asset management)

**(j) Use of Multi-Factor Authentication or Continuous Authentication Solutions**
- MFA implementation
- Single sign-on solutions
- Secured voice, video and text communications
- Emergency communication systems
- **ISO 27001 Mapping**: A.8.5 (Secure authentication)

### Article 23: Reporting Obligations

**Three-Stage Reporting Process:**

1. **Early Warning** (Article 23(3))
   - **Timeline**: Without undue delay, **≤24 hours** after becoming aware
   - **Content**: Indication of significant incident, assessment of severity
   - **Recipient**: CSIRT or competent authority

2. **Incident Notification** (Article 23(4))
   - **Timeline**: Without undue delay, **≤72 hours** after becoming aware
   - **Content**:
     - Initial assessment (severity, impact, indicators of compromise)
     - Initial incident response measures
     - Affected Member States (if cross-border)
   - **Update**: If incident ongoing, intermediate update **≤1 month** after initial notification

3. **Final Report** (Article 23(6))
   - **Timeline**: **≤1 month** after incident notification (may be extended to 2 months for complex cases)
   - **Content**:
     - Detailed description
     - Type of threat/root cause
     - Applied and ongoing mitigation measures
     - Cross-border impact (if applicable)

**Significant Incidents Criteria** (Article 23(3)):
- Caused significant operational disruption
- Led to financial losses for entity
- Affected other natural or legal persons by causing material losses

### Article 24: Supervisory and Enforcement Measures

**Powers of Competent Authorities:**
- Conduct on-site and off-site inspections
- Perform security scans
- Request information
- Issue binding instructions
- Order audits by independent bodies

**Penalties (Article 34):**

**Essential Entities:**
- **Minimum**: €10,000,000 or 2% of total worldwide annual turnover (whichever is higher)

**Important Entities:**
- **Minimum**: €7,000,000 or 1.4% of total worldwide annual turnover (whichever is higher)

**Aggravating Factors:**
- Duration of infringement
- Intentional/negligent nature
- Previous infringements
- Cooperation with authorities

**Management Liability (Article 20):**
- Members of management bodies can be held personally liable
- Required to approve cybersecurity risk management measures
- Must oversee implementation
- Must participate in training

## Structure of NIS2 Directive

### Chapter I: General Provisions (Articles 1-3)
- Article 1: Subject matter and scope
- Article 2: Minimum harmonisation
- Article 3: Definitions

### Chapter II: Coordinated Cybersecurity Framework (Articles 4-16)
- Article 6: National cybersecurity strategies
- Article 7: National competent authorities
- Article 8: Single points of contact
- Article 9: Computer security incident response teams (CSIRTs)
- Article 10: CSIRT tasks
- Article 14: Cooperation Group
- Article 15: CSIRTs network

### Chapter III: Governance (Articles 17-20)
- Article 18: Governance framework
- Article 19: Competent authorities powers
- Article 20: Management body accountability

### Chapter IV: Cybersecurity Risk Management and Reporting Obligations (Articles 21-26)
- Article 21: Cybersecurity risk management measures
- Article 23: Reporting obligations
- Article 24: Supervisory and enforcement measures
- Article 25: Use of standardisation
- Article 26: Voluntary reporting

### Chapter V: Information Sharing (Articles 27-28)
- Article 27: Information sharing arrangements
- Article 28: Vulnerability disclosure

### Chapter VI: Jurisdiction and Territoriality (Articles 29-30)

### Chapter VII: Penalties (Articles 31-34)
- Article 32: Penalties for essential entities
- Article 33: Penalties for important entities
- Article 34: Procedures for penalties

### Chapter VIII: International Cooperation (Articles 35-36)

### Chapter IX: Delegated and Implementing Acts (Articles 37-39)

### Chapter X: Final Provisions (Articles 40-42)

## ISO 27001:2022 Mapping

### NIS2 Article 21(2) to ISO 27001 Annex A

| NIS2 Requirement | ISO 27001 Controls | Coverage | Notes |
|------------------|-------------------|----------|-------|
| (a) Risk analysis & policies | Clause 6.1, A.5.1 | 100% | Full coverage |
| (b) Incident handling | A.5.24-A.5.28 | 90% | NIS2 reporting timelines specific |
| (c) Business continuity | A.5.29, A.5.30 | 80% | Refer to BCM specialist |
| (d) Supply chain security | A.5.19-A.5.23 | 95% | Good coverage |
| (e) Development & maintenance | A.8.9, A.8.25-A.8.34 | 90% | Secure SDLC covered |
| (f) Effectiveness assessment | Clause 9.1, 9.3 | 100% | Performance evaluation |
| (g) Training & hygiene | A.6.3 | 100% | Awareness covered |
| (h) Cryptography | A.8.24 | 100% | Full coverage |
| (i) Access control & assets | A.5.9-A.5.10, A.6.1-A.6.8, A.8.2-A.8.5 | 100% | Comprehensive coverage |
| (j) MFA | A.8.5 | 100% | Secure authentication |

**Overall ISO 27001 → NIS2 Coverage: ~80%**

**NIS2-Specific Requirements:**
1. 24h/72h/1-month reporting timelines
2. Management body accountability (personal liability)
3. Registration with national authority (BSI in Germany)
4. Sector-specific requirements may apply

## German NIS2UmsuCG Specifics

### Competent Authorities

**BSI (Bundesamt für Sicherheit in der Informationstechnik):**
- Central competent authority for NIS2
- Registration portal for entities
- CSIRT coordination
- Cross-sectoral supervision

**Sectoral Authorities:**
- **BaFin**: Financial sector (banks, insurance)
- **BNetzA**: Energy, telecommunications
- **Sector-specific**: Healthcare, transport, etc.

### Registration Requirement

**Timeline:**
- Entities must register within **6 months** after law's entry into force
- Online portal provided by BSI
- Self-assessment of applicability

**Information Required:**
- Entity identification (name, address, sector)
- Services provided
- Contact persons
- Declaration of essential/important status

### Penalties (NIS2UmsuCG)

**Essential Entities:**
- Up to **€10 million** or **2% of global annual turnover** (whichever is higher)

**Important Entities:**
- Up to **€7 million** or **1.4% of global annual turnover** (whichever is higher)

**Management Liability:**
- Board members can be held personally liable
- Potential personal fines
- Administrative sanctions

### Enforcement Powers (BSI/Sectoral Authorities)

- On-site inspections
- Request for documentation
- Security audits (entity bears costs)
- Binding orders
- Temporary prohibition of services (in severe cases)

## Key Differences: NIS1 vs. NIS2

| Aspect | NIS1 (2016/1148) | NIS2 (2022/2555) |
|--------|------------------|------------------|
| **Scope** | ~1,000 entities (Germany) | ~29,000 entities (Germany) |
| **Entity Types** | OESs, DSPs | Essential + Important |
| **Size Threshold** | Not specified | ≥50 employees OR ≥€10M turnover |
| **Reporting Timeline** | Varied by Member State | Harmonised: 24h/72h/1 month |
| **Penalties** | Up to Member States | Minimum €10M/€7M or 2%/1.4% turnover |
| **Management Liability** | Not specified | Personal accountability required |
| **Supply Chain** | Limited requirements | Explicit supply chain security measures |
| **Cybersecurity Measures** | General | Detailed (10 specific measures in Art. 21(2)) |

## Implementation Checklist

### Phase 1: Scoping & Assessment (Months 1-2)
- [ ] Determine if entity is essential or important
- [ ] Verify size threshold (≥50 employees OR ≥€10M)
- [ ] Identify applicable sector (Annex I or II)
- [ ] Register with BSI (within 6 months of law entry)

### Phase 2: Gap Analysis (Months 2-3)
- [ ] Assess current cybersecurity measures against Article 21(2)
- [ ] Identify gaps in technical/organisational measures
- [ ] Map existing ISO 27001 controls to NIS2 requirements
- [ ] Review incident response capabilities (24h/72h timelines)

### Phase 3: Risk Management Implementation (Months 3-9)
- [ ] **(a)** Develop/update risk analysis and security policies
- [ ] **(b)** Implement incident handling procedures (24h early warning, 72h notification)
- [ ] **(c)** Establish business continuity and disaster recovery plans
- [ ] **(d)** Implement supply chain security measures
- [ ] **(e)** Secure development and maintenance procedures
- [ ] **(f)** Define access control policies
- [ ] **(g)** Conduct basic cyber hygiene training
- [ ] **(h)** Implement cryptography policies
- [ ] **(i)** HR security, access control, asset management
- [ ] **(j)** Deploy multi-factor authentication

### Phase 4: Governance & Accountability (Months 6-12)
- [ ] Management body approves cybersecurity risk management measures
- [ ] Define roles and responsibilities
- [ ] Establish reporting lines to management board
- [ ] Implement management cybersecurity training

### Phase 5: Incident Reporting Setup (Months 9-12)
- [ ] Establish connection to BSI/CSIRT
- [ ] Define incident classification criteria
- [ ] Implement 24h early warning capability
- [ ] Create 72h incident notification workflow
- [ ] Develop final report template (1-month timeline)
- [ ] Test reporting procedures

### Phase 6: Documentation & Evidence (Months 10-12)
- [ ] Document cybersecurity risk management framework
- [ ] Maintain evidence of compliance with Article 21(2)
- [ ] Prepare for potential inspections
- [ ] Create audit-ready documentation

### Phase 7: Continuous Compliance (Ongoing)
- [ ] Regular security assessments
- [ ] Annual management reviews
- [ ] Continuous monitoring and improvement
- [ ] Staff training updates
- [ ] Incident response drills

## Sector-Specific Requirements

### Banking (BaFin Supervision)
- NIS2 + BAIT + MaRisk + DORA
- Integrated approach required
- BaFin as sectoral authority

### Healthcare
- Additional GDPR considerations
- Patient data protection
- Critical infrastructure status

### Energy (BNetzA Supervision)
- Critical infrastructure protection
- KRITIS catalogue
- Additional national security requirements

### Telecommunications (BNetzA Supervision)
- TKG (Telekommunikationsgesetz) requirements
- Network security obligations

## Voluntary Reporting (Article 26)

Entities may voluntarily report:
- Significant cyber threats (not yet materialised)
- Near-miss incidents
- Vulnerabilities

Benefits:
- Contributes to threat intelligence sharing
- May demonstrate good faith in supervision
- Supports cybersecurity ecosystem

## Cross-Border Cooperation

### EU Cooperation Mechanisms
- **Cooperation Group** (Article 14): Strategic cooperation, information exchange
- **CSIRTs Network** (Article 15): Operational cooperation, incident handling
- **Information Sharing**: Via secure channels

### Cross-Border Incidents
- Affected Member States notified
- Coordinated response
- Information sharing between authorities

## Resources

### Official Sources
- **EUR-Lex**: https://eur-lex.europa.eu/eli/dir/2022/2555/oj
- **European Commission NIS2**: https://digital-strategy.ec.europa.eu/en/policies/nis2-directive

### German Implementation
- **BSI NIS2 Information**: https://www.bsi.bund.de/EN/Topics/Industry_CI/NIS2/nis2_node.html
- **BMI (Federal Ministry of Interior)**: Legislative process updates

### Guidance Documents
- **ENISA**: EU Agency for Cybersecurity - NIS2 guidance
- **BSI**: Implementation guidance (published after law entry)

## FAQs

**Q: When must German companies comply with NIS2?**
A: Immediately upon entry into force of NIS2UmsuCG (expected before end of 2025). No transition period.

**Q: How do I know if my company is in scope?**
A: Check sector (Annex I or II) + size threshold (≥50 employees OR ≥€10M turnover).

**Q: Can I be ISO 27001 certified and still need NIS2 compliance?**
A: Yes. ISO 27001 covers ~80% of NIS2 requirements, but NIS2-specific items (reporting, registration, management liability) still apply.

**Q: What happens if I don't comply?**
A: Penalties up to €10M or 2% of turnover for essential entities, €7M or 1.4% for important entities. Management can be held personally liable.

**Q: Do I need to report all incidents?**
A: No, only "significant" incidents as defined in Article 23(3) criteria.

**Q: How does NIS2 relate to DORA?**
A: DORA is sector-specific (financial) and takes precedence. Financial entities comply with DORA, which is considered lex specialis to NIS2.

## Updates Log

- **November 13, 2025**: NIS2UmsuCG adopted by Bundestag
- **July 30, 2025**: Federal Cabinet adopted NIS2UmsuCG bill
- **February 2025**: Early Federal elections - legislative reset
- **October 17, 2024**: EU transposition deadline (Germany missed)
- **January 16, 2023**: NIS2 Directive entered into force
- **December 14, 2022**: NIS2 Directive adopted