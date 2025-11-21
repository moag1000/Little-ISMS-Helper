# ISO 31000:2018 - Risk Management Guidelines
## Quick Reference for Risk Management Specialist

### Standard Overview

**Full Title:** ISO 31000:2018 - Risk management — Guidelines

**Purpose:** Provides principles, framework, and process for managing risk. Can be used by any organization regardless of size, activity, or sector.

**Scope:** Generic risk management (not specific to information security)
- Enterprise risk management (ERM)
- Strategic risk
- Operational risk
- Financial risk
- Project risk
- Compliance risk
- Information security risk (when combined with ISO 27005)

**Latest Version:** ISO 31000:2018 (published 2018-02)
**Previous Version:** ISO 31000:2009 (superseded)

**Relationship to Other Standards:**
- **ISO 27005**: Applies ISO 31000 principles to information security risk
- **ISO 22301**: Applies ISO 31000 to business continuity risk
- **ISO 9001**: Quality management system can integrate risk-based thinking using ISO 31000
- **ISO 14001**: Environmental management system risk management

---

## Key Changes in 2018 Edition

**Major Updates from 2009:**

1. **Simplified Structure** - Less prescriptive, more flexible
   - Removed "PDCA" (Plan-Do-Check-Act) structure
   - Emphasized iterative, continuous nature of risk management

2. **Enhanced Principles** - Expanded from 11 principles to 8 refined principles
   - More focus on integration, human factors, continual improvement

3. **Framework vs. Process** - Clearer distinction
   - **Framework** (Clause 5): Organizational structure for managing risk
   - **Process** (Clause 6): Operational activities for managing risk

4. **Emphasis on Leadership** - Greater focus on management commitment and culture

5. **Dynamic Risk Management** - Recognition that risk is not static
   - Continuous monitoring and adaptation
   - Anticipate changes in external/internal context

6. **Human and Cultural Factors** - New principle recognizing human behavior affects risk

7. **Customization** - More explicit that ISO 31000 should be tailored to organization's context

---

## Standard Structure

### Clause 1: Scope
Defines applicability to all organizations and risk types

### Clause 2: Normative References
None (ISO 31000 is standalone, no dependencies)

### Clause 3: Terms and Definitions
Key terms (see Definitions section below)

### Clause 4: Principles (8 principles)
Foundation for effective risk management

### Clause 5: Framework (organizational foundation)
- 5.2: Leadership and commitment
- 5.3: Integration
- 5.4: Design
- 5.5: Implementation
- 5.6: Evaluation
- 5.7: Improvement

### Clause 6: Process (operational activities)
- 6.2: Communication and consultation
- 6.3: Scope, context, and criteria
- 6.4: Risk assessment
  - 6.4.1: General
  - 6.4.2: Risk identification
  - 6.4.3: Risk analysis
  - 6.4.4: Risk evaluation
- 6.5: Risk treatment
- 6.6: Monitoring and review
- 6.7: Recording and reporting

---

## Clause 4: Principles

**Purpose:** Characteristics of effective risk management

### Principle 1: Integrated

**Risk management is an integral part of all organizational activities**

**What This Means:**
- Risk management embedded in governance, strategy, planning, operations
- Not a standalone activity or separate department
- Risk considerations in all decisions (strategic, tactical, operational)

**How to Implement:**
- Include risk assessment in strategic planning
- Embed risk criteria in procurement decisions
- Integrate risk KPIs into balanced scorecard
- Risk owners assigned to business process owners (not just risk team)

**Example:**
```
Traditional (Siloed):
- Risk department performs annual risk assessment
- Risk register maintained separately
- Business makes decisions, risk team reviews afterward

Integrated (ISO 31000):
- Risk assessment integrated into annual strategic planning session
- Risk appetite guides business unit budget allocation
- New projects require risk assessment before approval
- Risk metrics embedded in departmental KPIs
```

### Principle 2: Structured and Comprehensive

**A structured and comprehensive approach contributes to consistent and comparable results**

**What This Means:**
- Systematic, not ad-hoc
- Consistent methodology across organization
- All types of risks considered (not just financial or compliance)
- Appropriate to context (not one-size-fits-all)

**How to Implement:**
- Documented risk management framework
- Standardized risk assessment templates
- Defined risk categories (strategic, operational, financial, compliance, reputational)
- Consistent risk scales across organization (but can vary by risk type)

**Example:**
```
Department A: Uses 3×3 risk matrix
Department B: Uses gut feeling
Department C: Doesn't assess risk

ISO 31000 Structured Approach:
- Organization-wide 5×5 risk matrix
- All departments use same impact/likelihood scales
- Risk register consolidated at enterprise level
- Comparable: Can prioritize Risk A (Dept A) vs. Risk B (Dept B)
```

### Principle 3: Customized

**The risk management framework and process are customized and proportionate to the organization's external and internal context related to its objectives**

**What This Means:**
- Not prescriptive (ISO 31000 is guidance, not requirements)
- Tailor to organization's size, complexity, risk profile
- Reflect organizational culture, capabilities, resources
- Adapt to industry, regulatory environment

**How to Implement:**
- Small business: Simplified risk register, qualitative assessment, annual review
- Large enterprise: Complex risk taxonomy, quantitative assessment, quarterly reporting, dedicated risk team
- Healthcare: Focus on patient safety, regulatory, data privacy risks
- Manufacturing: Focus on operational, supply chain, safety risks

**Example:**
```
Startup (10 employees):
- Risk appetite statement: 1 page
- Risk register: Excel spreadsheet, 15 risks
- Assessment: Qualitative (High/Medium/Low)
- Review: Quarterly management meeting

Global Corporation (50,000 employees):
- Risk appetite framework: 20-page document, board-approved
- Risk register: GRC software platform, 500+ risks
- Assessment: Quantitative (Monte Carlo, ALE), qualitative for strategic risks
- Review: Monthly risk committee, quarterly board reporting
- Dedicated: Chief Risk Officer, 20-person risk team
```

### Principle 4: Inclusive

**Appropriate and timely involvement of stakeholders enables their knowledge, views, and perceptions to be considered**

**What This Means:**
- Risk management is not done in isolation by risk team
- Engage stakeholders with relevant knowledge
- Consider diverse perspectives (different departments, levels, backgrounds)
- Stakeholder involvement improves risk identification, analysis, buy-in

**How to Implement:**
- Interview business process owners for impact assessment
- Workshop with IT, security, operations for risk identification
- Consult legal, compliance for regulatory risk assessment
- Board involvement in risk appetite setting
- Employee feedback on operational risks

**Example:**
```
Risk: Data Center Outage

Stakeholders to Consult:
- IT Infrastructure (likelihood, technical controls)
- Business Units (impact on operations)
- Finance (financial impact calculation)
- Legal (contractual obligations, SLA penalties)
- Customers (service expectations, tolerance)
- Data Center Vendor (reliability data, SLA terms)

Outcome: Comprehensive risk assessment incorporating all perspectives
```

### Principle 5: Dynamic

**Risks can emerge, change, or disappear as an organization's external and internal context changes**

**What This Means:**
- Risk management is continuous, not one-time
- Risk landscape constantly evolving (new threats, vulnerabilities, context changes)
- Anticipate and respond to changes
- Regular monitoring, not just annual review

**How to Implement:**
- Continuous monitoring of key risk indicators (KRIs)
- Trigger-based risk reviews (after incidents, major changes)
- Horizon scanning (emerging threats, regulatory changes)
- Agile risk management (adapt quickly to new risks)

**Example:**
```
Static (Annual Review Only):
- 2019: Assess risk of data breach
- 2020-2023: No updates
- 2024: Risk assessment outdated, doesn't reflect ransomware trend

Dynamic (ISO 31000):
- 2019: Assess data breach risk (score: 12)
- 2020: Ransomware surge → Re-assess (score: 18, treatment required)
- 2021: Implement MFA → Residual risk decreases (score: 8)
- 2022: New ransomware variant → Re-assess (score: 10, monitor)
- 2023: Zero incidents, training effective → Score stable (10)
- 2024: Continuous monitoring, quarterly updates
```

### Principle 6: Best Available Information

**The inputs to risk management are based on historical and current information, as well as future expectations**

**What This Means:**
- Use data and evidence, not just intuition
- Multiple sources: historical incidents, industry benchmarks, threat intelligence, expert judgment
- Balance quantitative data with qualitative insights
- Acknowledge limitations and uncertainties

**How to Implement:**
- Historical: Analyze past incidents (frequency, impact)
- Current: Vulnerability scans, threat intelligence feeds, audit findings
- Future: Trend analysis, scenario planning, expert forecasts
- Validate: Cross-check data sources, peer review assessments

**Example:**
```
Risk: Phishing Attack

Poor Information:
- Likelihood assessment: "Manager gut feeling: Unlikely (2)"
- No data to support

Best Available Information (ISO 31000):
- Historical: Our organization had 5 phishing incidents in past 2 years
- Current: Last security awareness training: 50% employee pass rate
- Industry: Verizon DBIR reports 36% of breaches involve phishing
- Threat Intelligence: Phishing campaigns targeting our industry increased 40% this year
- Expert Judgment: CISO estimates 60% probability based on above data
→ Likelihood: 4 (Likely) - data-backed assessment
```

### Principle 7: Human and Cultural Factors

**Human behaviour and culture significantly influence all aspects of risk management at each level and stage**

**What This Means:**
- Risk management not purely technical or analytical
- Human factors: Perception, bias, behavior, competence, communication
- Organizational culture: Risk appetite, risk-taking, learning from failures
- Acknowledge and address human element

**How to Implement:**
- Training and awareness (risk management, security awareness)
- Risk culture assessment (surveys, interviews)
- Address cognitive biases (overconfidence, normalcy bias, groupthink)
- Psychological safety (encourage reporting of risks/incidents without blame)
- Leadership tone (management commitment, "tone from the top")

**Example:**
```
Technical Controls Only (Insufficient):
- Implement MFA, firewall, IDS
- Users still fall for phishing due to lack of awareness
- Controls bypassed due to poor usability (users write passwords on sticky notes)

Human and Cultural Factors (ISO 31000):
- Technical Controls: MFA, firewall, IDS
- Human Factors:
  - Security awareness training (quarterly, interactive)
  - Simulated phishing exercises (measure, improve)
  - Usable security (password manager provided)
  - Positive culture (reward security reporting, no blame for mistakes)
  - Leadership commitment (CISO reports to board, security KPIs in executive bonuses)
→ Holistic risk management addressing both technical and human factors
```

### Principle 8: Continual Improvement

**Risk management is continually improved through learning and experience**

**What This Means:**
- Risk management maturity evolves over time
- Lessons learned from incidents, near-misses, exercises
- Regular evaluation of risk management effectiveness
- Adapt methodology based on feedback

**How to Implement:**
- Post-incident reviews (what went well, what to improve)
- Exercise debriefs (test risk management process)
- Risk management maturity assessments (annual self-assessment)
- Benchmarking (compare to peers, best practices)
- Continuous improvement plan (address gaps, enhance capabilities)

**Example:**
```
Continual Improvement Cycle:

Year 1 (Baseline):
- Implement basic risk register
- Annual risk assessment
- Qualitative (High/Medium/Low)

Year 2 (Improve):
- Lesson learned: Annual assessment missed emerging ransomware threat
- Improvement: Add quarterly risk reviews
- Implement 5×5 risk matrix (more granular)

Year 3 (Mature):
- Lesson learned: Difficult to prioritize across departments
- Improvement: Introduce quantitative assessment (ALE) for top 10 risks
- Implement KRIs for continuous monitoring

Year 4 (Optimize):
- Lesson learned: Risk data siloed in spreadsheets
- Improvement: Deploy GRC platform for integrated risk management
- Automate KRI dashboards

Year 5 (Leading):
- Benchmark: Maturity level 4/5 (compared to peers at level 2-3)
- Continuous improvement: Enhance predictive analytics, AI-based threat detection
```

---

## Clause 5: Framework

**Purpose:** Organizational foundation for designing, implementing, and improving risk management

**Key Concept:** Risk management framework is the set of components that provide foundations and organizational arrangements for managing risk

**Components:**
- Leadership and commitment (5.2)
- Integration into organizational processes (5.3)
- Design tailored to organization (5.4)
- Implementation across organization (5.5)
- Evaluation of framework effectiveness (5.6)
- Improvement of framework (5.7)

**Framework vs. Process:**
- **Framework**: Organizational structure, governance, policies, culture (Clause 5)
- **Process**: Operational activities to identify, analyze, treat risks (Clause 6)

### 5.2 Leadership and Commitment

**Top management shall demonstrate leadership and commitment by:**

**a) Ensuring risk management is integrated into all organizational activities**
- Risk considerations in strategic planning, budgeting, project approvals
- Risk management not delegated to risk department only

**b) Accountability for risk management at all levels**
- Risk owners assigned (business process owners, project managers)
- Clear escalation paths (when to escalate risks to management, board)

**c) Allocating adequate resources**
- Risk management team (if needed)
- Tools and technology (GRC platforms, risk software)
- Training and awareness budget
- Time allocation for risk activities

**d) Communicating benefits of risk management**
- Link to strategic objectives (how risk management enables objectives)
- Success stories (risks avoided, incidents prevented, treatment ROI)

**e) Ensuring risk management achieves intended outcomes**
- Risk management objectives defined (e.g., reduce critical risks by 20%)
- Performance metrics tracked (e.g., % risks within appetite)
- Regular reporting to management and board

**Example Leadership Commitment:**
```
CEO Statement on Risk Management:

"Effective risk management is fundamental to achieving our strategic objectives.
I am personally committed to ensuring risk management is embedded in everything
we do, from strategic planning to daily operations.

I expect all managers to:
- Identify and assess risks in their areas of responsibility
- Implement treatment plans for risks exceeding our appetite
- Report significant risks to the Risk Committee quarterly
- Foster a culture where employees feel safe reporting risks and incidents

We have allocated €500,000 for risk management initiatives this year, including
a GRC platform, cybersecurity enhancements, and employee training.

Risk management is not about eliminating all risk—it's about taking informed
risks to achieve our goals while protecting our people, assets, and reputation.

[Signature]
John Smith, CEO"
```

### 5.3 Integration

**Risk management should be integrated into all organizational activities, not treated as standalone**

**Integration Points:**

**Strategic Level:**
- Risk appetite defined and approved by board
- Strategic risks identified during strategic planning
- Risk profile influences strategic direction (e.g., market entry, M&A decisions)

**Governance Level:**
- Risk management policy approved by board
- Risk committee established (or integrated into audit committee)
- Risk management roles in organizational structure (CRO, risk managers)

**Operational Level:**
- Risk assessment required for new projects
- Risk criteria in procurement decisions (vendor risk, contract terms)
- Risk considerations in change management
- Incident management feeds into risk management (lessons learned)

**Financial Level:**
- Risk-based budgeting (higher risk = higher contingency)
- Risk financing (insurance, reserves)
- Risk metrics in financial reporting (risk-adjusted performance)

**Compliance Level:**
- Integrated compliance risk register
- Risk-based audit planning (audit high-risk areas more frequently)
- Regulatory risk monitoring

**Example Integration:**
```
Project Approval Process (Integrated Risk Management):

1. Project Proposal Submitted
   ↓
2. Risk Assessment Required (using risk matrix)
   - Identify project risks
   - Assess impact and likelihood
   - Calculate risk score
   ↓
3. Risk Evaluation
   - If total project risk > threshold (e.g., 15 High):
     → Requires executive approval
   - If residual risk > appetite:
     → Treatment plan required before approval
   ↓
4. Approval Decision
   - Projects with acceptable risk: Approved
   - High-risk projects: Approved with conditions (treatment, monitoring)
   - Unacceptable risk: Rejected or deferred until treated
   ↓
5. Ongoing Monitoring
   - Project risks monitored monthly
   - Escalation if risk profile changes
```

### 5.4 Design

**The framework should be designed to suit the organization's external and internal context**

**Design Considerations:**

**a) Understanding the Organization and Its Context**
- External: Industry, regulatory environment, economic conditions, threat landscape
- Internal: Culture, capabilities, objectives, structure, stakeholders

**b) Articulating Risk Management Commitment**
- Risk management policy (purpose, scope, principles, accountabilities)
- Approved by top management / board

**c) Assigning Organizational Roles, Responsibilities, and Authorities**
- **Board/Executives**: Set risk appetite, oversee framework, approve high risks
- **Chief Risk Officer (CRO)**: Own risk management framework, facilitate process
- **Risk Committee**: Governance oversight, review risk register, escalate to board
- **Risk Owners**: Managers responsible for specific risks (treatment, monitoring)
- **Risk Managers**: Support risk owners, facilitate assessments
- **All Employees**: Identify and report risks, comply with risk policies

**d) Allocating Resources**
- Personnel (risk team, if needed)
- Financial (budget for risk treatment, tools)
- Technology (GRC platforms, risk analytics)
- Time (allocate time for risk activities)

**e) Establishing Communication and Consultation**
- Internal: Employees, management, board
- External: Regulators, customers, partners, auditors
- Mechanisms: Reports, dashboards, meetings, training

**Example Risk Management Policy:**
```
Risk Management Policy

1. Purpose
This policy establishes the framework for managing risk across [Organization].
Effective risk management enables us to achieve strategic objectives while
protecting stakeholders and maintaining regulatory compliance.

2. Scope
Applies to all employees, contractors, and subsidiaries. Covers all risk types:
strategic, operational, financial, compliance, reputational, IT, security.

3. Risk Appetite
Our risk appetite varies by risk category:
- Financial: Moderate (will accept risks with potential return > 2× cost)
- Compliance: Low (zero tolerance for major regulatory breaches)
- Reputational: Low (protect brand and customer trust)
- Operational: Moderate (accept operational risks for innovation)

Risks exceeding appetite require Board approval.

4. Principles
We apply ISO 31000 principles: Integrated, Structured, Customized, Inclusive,
Dynamic, Best Information, Human Factors, Continual Improvement.

5. Risk Management Process
5.1 Risk Assessment: Annual enterprise-wide, quarterly for high-risk areas
5.2 Risk Treatment: Plans required for risks exceeding appetite
5.3 Risk Monitoring: Monthly KRIs, quarterly risk register review
5.4 Risk Reporting: Quarterly to Risk Committee, annually to Board

6. Roles and Responsibilities
- Board: Approve risk appetite, oversee framework
- CEO: Accountable for risk management
- CRO: Own framework, facilitate process
- Risk Committee: Governance, review register
- Managers: Risk owners, implement treatment
- Employees: Identify and report risks

7. Review
This policy reviewed annually or after significant changes.

Approved: [Signature], [Date]
John Smith, CEO
```

### 5.5 Implementation

**Implementing the framework throughout the organization**

**Implementation Steps:**

**a) Develop Implementation Plan**
- Timeline (milestones, deadlines)
- Responsibilities (who does what)
- Resources (budget, personnel)
- Success criteria (what does success look like?)

**b) Define When and How Different Types of Risks Should Be Managed**
- Continuous: Operational risks (IT, security, safety)
- Periodic: Strategic risks (annual strategic planning)
- Trigger-based: Project risks (per project), compliance risks (regulatory change)

**c) Apply Risk Management Process (Clause 6)**
- Establish context and criteria
- Conduct risk assessments
- Implement risk treatment
- Monitor and review

**d) Ensure Compliance**
- Legal and regulatory requirements met (GDPR, NIS2, ISO 27001)
- Internal policies and standards followed
- Audit and assurance activities

**Example Implementation Plan:**
```
Risk Management Framework Implementation Plan

Phase 1: Foundation (Months 1-3)
- Establish Risk Committee (Month 1)
- Develop Risk Management Policy (Month 1)
- Define risk appetite per category (Month 2)
- Design risk matrix (5×5) and criteria (Month 2)
- Assign risk owners (Month 3)
- Budget: €50,000 (consulting, policy development)

Phase 2: Initial Assessment (Months 4-6)
- Conduct enterprise-wide risk assessment (Month 4-5)
  - Identify risks (workshops with departments)
  - Analyze risks (impact, likelihood)
  - Evaluate and prioritize
- Develop risk register (Month 5)
- Identify risks exceeding appetite (Month 6)
- Budget: €100,000 (consultant support, workshops)

Phase 3: Treatment Planning (Months 7-9)
- Develop treatment plans for high/critical risks (Month 7-8)
- Obtain management approval for treatment budgets (Month 8)
- Begin treatment implementation (Month 9)
- Budget: €500,000 (risk treatments - cybersecurity, BC, controls)

Phase 4: Operationalization (Months 10-12)
- Implement KRIs and dashboards (Month 10)
- Establish quarterly risk review process (Month 11)
- Train employees on risk management (Month 11-12)
- First quarterly risk report to Risk Committee (Month 12)
- Budget: €150,000 (GRC platform, training, dashboards)

Phase 5: Continual Improvement (Ongoing)
- Monitor KRIs (continuous)
- Quarterly risk register reviews
- Annual risk assessment refresh
- Annual framework evaluation and improvement
- Budget: €200,000/year (ongoing)

Total Year 1 Budget: €1,000,000
Success Criteria:
- 100% of high/critical risks have treatment plans (by Month 9)
- 80% of treatments on track (by Month 12)
- 90% of risks within appetite (by Month 18)
- Risk management maturity level 3/5 (by Month 24)
```

### 5.6 Evaluation

**Periodically evaluate the effectiveness of the risk management framework**

**Evaluation Questions:**

**Framework Design:**
- Is framework appropriate for organization's context?
- Are roles and responsibilities clear?
- Are resources adequate?

**Framework Implementation:**
- Is framework implemented consistently across organization?
- Are risk assessments conducted as planned?
- Are treatment plans implemented on time?

**Framework Performance:**
- Are risks being identified proactively?
- Are treatment plans effective (residual risk reduced)?
- Are incidents decreasing (if risk management effective)?
- Are objectives being achieved?

**Framework Integration:**
- Is risk management integrated into decision-making?
- Do business units use risk criteria in planning?
- Is risk reporting timely and useful?

**Evaluation Methods:**
- Internal audits
- Maturity assessments (e.g., CMMI for risk management)
- Stakeholder surveys (is risk management adding value?)
- KPI review (trend analysis)
- Benchmarking (compare to peers, industry best practices)

**Example Evaluation Report:**
```
Risk Management Framework Evaluation (Year 1)

1. Objectives Achievement
   ✅ Objective 1: Establish risk management framework → Achieved (Month 3)
   ✅ Objective 2: Conduct enterprise risk assessment → Achieved (Month 5)
   ⚠️ Objective 3: 90% high risks treated → Partial (75% treated, 25% in progress)
   ✅ Objective 4: Quarterly reporting to Risk Committee → Achieved (4 reports)

2. Framework Effectiveness
   ✅ Risk identification: 127 risks identified (comprehensive coverage)
   ✅ Risk assessment: Consistent methodology, 100% risks scored
   ⚠️ Risk treatment: 25% of treatment plans behind schedule (resource constraints)
   ✅ Risk monitoring: KRIs tracked monthly, no critical risks missed

3. Performance Metrics
   - Total risks: 127 (38 high/critical, 54 medium, 35 low)
   - Risks exceeding appetite: 38 → 15 (60% reduction) ✅
   - Treatment completion rate: 75% ✅
   - Incidents vs. risks: 3 incidents, all had identified risks (validation)
   - Risk-adjusted decisions: 12 projects approved, 2 rejected due to risk

4. Stakeholder Feedback
   - Management satisfaction: 8/10 (survey)
   - Business units: "Risk assessments useful but time-consuming" (feedback)
   - Board: "Good progress, need faster treatment" (minutes)

5. Findings and Recommendations
   ✅ Strengths:
   - Strong leadership commitment
   - Comprehensive risk identification
   - Consistent methodology

   ⚠️ Areas for Improvement:
   - Accelerate treatment plan implementation (add resources)
   - Simplify risk assessment process for efficiency
   - Enhance risk culture through more training

   📋 Recommendations for Year 2:
   1. Hire additional risk analyst (€80k/year)
   2. Implement GRC platform to streamline process (€50k)
   3. Increase training budget (€30k)
   4. Simplify risk assessment template (reduce from 20 to 10 questions)
   5. Introduce risk champions in each department (network of advocates)

6. Conclusion
   Risk management framework successfully implemented. Significant progress
   in risk reduction (60% decrease in risks exceeding appetite). Framework is
   fit for purpose but requires enhancements for efficiency and speed.

   Maturity Level: 3/5 (Defined) - Target for Year 2: Level 4 (Managed)
```

### 5.7 Improvement

**Continually improve the suitability, adequacy, and effectiveness of the framework**

**Sources of Improvement:**
- Evaluation findings (5.6 above)
- Incident lessons learned
- Internal/external audits
- Changes in context (new threats, regulations, business model)
- Stakeholder feedback
- Benchmarking (best practices from peers)

**Improvement Areas:**
- **Policy**: Update risk management policy (annually or as needed)
- **Methodology**: Refine risk assessment approach (scales, criteria)
- **Tools**: Upgrade technology (GRC platforms, analytics)
- **Competence**: Enhance skills (training, certifications, hiring)
- **Culture**: Improve risk awareness and behavior
- **Integration**: Deepen integration into business processes

**Improvement Cycle:**
```
1. Evaluate (5.6)
   ↓
2. Identify Gaps/Opportunities
   ↓
3. Develop Improvement Plan
   ↓
4. Implement Changes
   ↓
5. Monitor Results
   ↓
6. Evaluate Again (continuous)
```

---

## Clause 6: Process

**Purpose:** Operational activities for managing risk (identify, analyze, evaluate, treat, monitor)

**Process Overview:**
```
┌─────────────────────────────────────────────────────────┐
│  Communication and Consultation (6.2) - Continuous      │
│  Recording and Reporting (6.7) - Continuous             │
└─────────────────┬───────────────────────────────────────┘
                  │
                  ▼
      ┌───────────────────────────┐
      │ Scope, Context, Criteria  │
      │         (6.3)             │
      └────────────┬──────────────┘
                   │
                   ▼
      ┌───────────────────────────┐
      │   Risk Assessment (6.4)    │
      │  ┌─────────────────────┐  │
      │  │ Identification (6.4.2)│  │
      │  └──────────┬───────────┘  │
      │             ▼              │
      │  ┌─────────────────────┐  │
      │  │  Analysis (6.4.3)    │  │
      │  └──────────┬───────────┘  │
      │             ▼              │
      │  ┌─────────────────────┐  │
      │  │ Evaluation (6.4.4)   │  │
      │  └─────────────────────┘  │
      └────────────┬──────────────┘
                   │
                   ▼
      ┌───────────────────────────┐
      │   Risk Treatment (6.5)     │
      └────────────┬──────────────┘
                   │
                   ▼
      ┌───────────────────────────┐
      │ Monitoring & Review (6.6)  │◄───┐
      └────────────┬──────────────┘    │
                   │                    │
                   └────────────────────┘
                        (Iterative)
```

### 6.2 Communication and Consultation

**Purpose:** Facilitate understanding and support risk management throughout organization

**Communication vs. Consultation:**
- **Communication**: One-way (inform, report)
- **Consultation**: Two-way (gather input, discuss, collaborate)

**When to Communicate/Consult:**
- Throughout entire risk process (not just at end)
- Before decisions made (gather input)
- During implementation (coordinate, align)
- After incidents (lessons learned)

**With Whom:**
- **Internal**: Employees, management, board, risk owners
- **External**: Regulators, customers, suppliers, partners, auditors, public

**What to Communicate:**
- Risk management process and framework
- Risk assessment results (register, heatmaps)
- Treatment plans and progress
- Monitoring results (KRIs, dashboards)
- Incidents and lessons learned

**How to Communicate:**
- **Reports**: Risk register, quarterly reports, annual reports
- **Dashboards**: Real-time KRIs, risk heatmaps
- **Meetings**: Risk committee, management reviews, board updates
- **Training**: Risk awareness, methodology training
- **Alerts**: Critical risk notifications, incident alerts

**Example Communication Plan:**
```
Stakeholder Communication Matrix

| Stakeholder | What | When | How | Purpose |
|-------------|------|------|-----|---------|
| **Board** | Risk appetite, top 10 risks, treatment status | Quarterly | Board pack (15-page report) | Governance oversight |
| **Executive Committee** | Risk register, KRIs, treatment progress, escalations | Monthly | 30-min meeting + dashboard | Decision-making, resource allocation |
| **Risk Committee** | Detailed risk register, treatment plans, deep dives | Monthly | 2-hour meeting + detailed reports | Governance, review, approve |
| **Risk Owners** | Assigned risks, treatment actions, deadlines, KRIs | Weekly/Monthly | Email, task management system | Accountability, execution |
| **All Employees** | Risk awareness, how to report risks, policies | Quarterly | Training sessions, newsletters, intranet | Culture, engagement |
| **Regulators** | Compliance status, material incidents, risk assessments | Annually/Ad-hoc | Formal reports, meetings | Regulatory compliance |
| **Customers (B2B)** | Security posture, BC capabilities, major incidents | Annually/Ad-hoc | SOC 2 reports, meetings | Trust, transparency |
```

### 6.3 Scope, Context, and Criteria

**Purpose:** Define boundaries and parameters for risk management

**6.3.1 Scope**

**Define What is Included/Excluded:**
- Organizational scope (departments, subsidiaries, locations)
- Activity scope (projects, processes, systems)
- Risk types (strategic, operational, financial, compliance, IT, reputation)
- Time horizon (1 year, 3 years, 5 years)

**Example Scope Statement:**
> "This risk assessment covers enterprise-wide strategic and operational risks
> for [Organization] and its 3 subsidiaries, for the period 2024-2026.
> Includes: Strategic risks, IT/cybersecurity risks, operational risks, financial risks, compliance risks (GDPR, NIS2), reputational risks.
> Excludes: Project-specific risks (managed separately), market risks (managed by Treasury), environmental risks (managed by EHS team)."

**6.3.2 External and Internal Context**

**External Context:**
- **Regulatory**: Laws, regulations, compliance requirements (GDPR, NIS2, ISO 27001)
- **Economic**: Market conditions, economic trends, financial environment
- **Competitive**: Industry landscape, competitors, market share
- **Technological**: Emerging technologies, threat landscape, digital transformation
- **Social**: Societal expectations, stakeholder perceptions, cultural factors
- **Geopolitical**: Political stability, international relations, sanctions

**Internal Context:**
- **Governance**: Structure, accountability, decision-making processes
- **Strategy**: Mission, vision, strategic objectives, risk appetite
- **Capabilities**: Resources, skills, technology, processes
- **Culture**: Values, risk culture, attitudes toward risk
- **Information Systems**: IT architecture, data, security posture
- **Stakeholders**: Employees, customers, partners, shareholders

**Why Context Matters:**
- Influences which risks are relevant (e.g., GDPR risk for EU organizations)
- Affects risk appetite (e.g., startups accept higher risk than utilities)
- Determines available treatments (e.g., resources, capabilities)

**6.3.3 Risk Criteria**

**Define How Risk Will Be Evaluated:**

**Impact Criteria:**
- Categories: Financial, Operational, Reputational, Legal, Strategic
- Scales: 1-5 (Negligible to Critical) or qualitative (Low/Medium/High/Critical)
- Thresholds: Define each level (e.g., Critical = > €1M loss)

**Likelihood Criteria:**
- Scales: 1-5 (Rare to Almost Certain) or qualitative
- Definitions: Rare = < 10% probability, Almost Certain = > 80%

**Risk Evaluation Criteria:**
- **Risk Appetite**: Maximum risk willing to accept per category
- **Risk Tolerance**: Acceptable variation around appetite
- **Risk Capacity**: Maximum risk organization can absorb

**Example Risk Criteria:**
```
Risk Criteria Framework

Impact Scale (Financial):
1 - Negligible: < €10,000
2 - Minor: €10,000 - €100,000
3 - Moderate: €100,000 - €1,000,000
4 - Major: €1,000,000 - €10,000,000
5 - Critical: > €10,000,000

Likelihood Scale:
1 - Rare: < 10% probability per year
2 - Unlikely: 10-30% probability
3 - Possible: 30-50% probability
4 - Likely: 50-80% probability
5 - Almost Certain: > 80% probability

Risk Matrix (5×5):
Risk Score = Impact × Likelihood (range: 1-25)

Risk Levels:
- Low (1-6): Green - Accept and monitor
- Medium (8-12): Yellow - Evaluate treatment, may accept
- High (15-16): Orange - Treat, management approval to accept
- Critical (20-25): Red - Treat immediately, board approval to accept

Risk Appetite (by category):
- Strategic: 12 (High) - Will accept high risks for strategic opportunities
- Financial: 9 (Medium) - Moderate financial risk tolerance
- Compliance: 6 (Medium) - Low tolerance for regulatory breaches
- Operational: 12 (High) - Accept operational risks for efficiency
- Reputational: 6 (Medium) - Protect brand and customer trust
- IT/Cybersecurity: 9 (Medium) - Balanced approach to security risk
```

### 6.4 Risk Assessment

**Purpose:** Identify, analyze, and evaluate risk

#### 6.4.1 General

**Risk Assessment Process:**
1. Risk Identification: What can happen?
2. Risk Analysis: What are the consequences and likelihoods?
3. Risk Evaluation: Which risks require treatment?

**Iterative:** Risk assessment repeated regularly and after changes

#### 6.4.2 Risk Identification

**Purpose:** Find, recognize, and describe risks

**Techniques:**
- **Workshops**: Facilitated sessions with stakeholders
- **Interviews**: One-on-one with subject matter experts
- **Checklists**: Industry risk lists, threat catalogs
- **SWOT Analysis**: Strengths, Weaknesses, Opportunities, Threats
- **Scenario Analysis**: "What if" scenarios
- **Brainstorming**: Creative identification
- **Delphi Method**: Expert panel consensus
- **Root Cause Analysis**: Analyze past incidents for underlying risks
- **Bow Tie Analysis**: Visualize threats, controls, consequences

**What to Identify:**
- **Risk Events**: What can happen? (e.g., "Data breach")
- **Risk Sources**: What causes it? (e.g., "Phishing attack")
- **Risk Consequences**: What are impacts? (e.g., "€500k fine, reputation damage")

**Risk Statement Format:**
```
"[Event] caused by [Source] resulting in [Consequence]"

Examples:
- "Data breach caused by ransomware attack resulting in €2M GDPR fine and customer churn"
- "Project delay caused by key personnel leaving resulting in €500k revenue loss and customer dissatisfaction"
- "Supply chain disruption caused by supplier bankruptcy resulting in 3-month production halt and €5M revenue loss"
```

**Risk Categories (Example Taxonomy):**
1. **Strategic Risks**: M&A, market entry, competition, innovation
2. **Operational Risks**: Process failure, human error, supply chain, IT outage
3. **Financial Risks**: Credit, liquidity, FX, interest rate (often managed separately)
4. **Compliance Risks**: Regulatory breaches, legal violations
5. **Reputational Risks**: Brand damage, customer trust, ESG issues
6. **IT/Cybersecurity Risks**: Data breach, ransomware, system failure
7. **Human Resources Risks**: Key person loss, skills shortage, safety
8. **External Risks**: Natural disasters, pandemic, geopolitical

#### 6.4.3 Risk Analysis

**Purpose:** Understand risk nature and determine level of risk

**Qualitative Analysis:**
- Use descriptive scales (High/Medium/Low or 1-5)
- Fast, suitable when data limited
- Subjective but consistent if criteria defined

**Semi-Quantitative:**
- Assign numerical values to qualitative scales (e.g., High = 4)
- Combine to get risk score (Impact × Likelihood)
- Balances speed and precision

**Quantitative Analysis:**
- Use numerical values (€, probabilities)
- Techniques: ALE (Annual Loss Expectancy), Monte Carlo simulation, decision trees
- Precise but requires data and expertise

**Factors to Consider:**
- **Likelihood**: How often might this occur? Based on historical data, expert judgment, threat intelligence
- **Consequences**: What would be the impact? Consider multiple impact types (financial, operational, reputational, etc.)
- **Existing Controls**: What controls are already in place? How effective are they?
- **Vulnerabilities**: What weaknesses could be exploited?
- **Uncertainty**: How confident are we in our assessment? Document assumptions and limitations

**Example Risk Analysis:**
```
Risk: Ransomware Attack on File Servers

Likelihood Analysis:
- Historical: 2 ransomware incidents in past 3 years (industry-wide surge)
- Threat Intelligence: Ransomware campaigns targeting our sector increasing
- Vulnerabilities: Some users fall for phishing, legacy systems
- Existing Controls: Antivirus (70% effective), email filtering (80% effective), backups (90% recovery)
- Expert Judgment: CISO estimates 40% probability per year
→ Likelihood: 4 (Likely)

Impact Analysis (if backups fail):
- Financial: €500k (ransom) + €200k (recovery) + €300k (downtime) = €1M
- Operational: 2-week recovery time, major disruption
- Reputational: Moderate (if data not exfiltrated)
- Legal: Possible GDPR if personal data affected
→ Impact: 4 (Major)

Risk Score: 4 × 4 = 16 (Critical)

Residual Risk (with backups working):
- Impact reduced to 2 (Minor) - can recover, minimal ransom payment pressure
- Residual Risk: 4 × 2 = 8 (Medium)
```

#### 6.4.4 Risk Evaluation

**Purpose:** Compare risk against criteria to determine which risks need treatment

**Evaluation Steps:**

1. **Compare Risk Score to Risk Appetite**
```
Risk: Score 16 (Critical)
Risk Appetite (IT/Cyber): 9 (Medium)
Result: 16 > 9 → Treatment Required
```

2. **Prioritize Risks**
- Rank by risk score (highest first)
- Consider additional factors:
  - Trend (increasing/decreasing?)
  - Velocity (how fast could risk materialize?)
  - Manageability (can we treat it?)
  - Regulatory (compliance mandate?)

3. **Categorize Risks**
- **Accept**: Within appetite, monitor
- **Treat**: Exceeds appetite, action required
- **Escalate**: Beyond organizational capacity, inform board/executives

**Example Risk Evaluation:**
```
Enterprise Risk Register (Top 10 by Score)

| Rank | Risk ID | Risk | Score | Appetite | Status | Priority |
|------|---------|------|-------|----------|--------|----------|
| 1 | R-042 | Ransomware attack | 20 | 9 | Exceeds | Critical - Treat Immediate |
| 2 | R-015 | Key supplier bankruptcy | 16 | 12 | Exceeds | High - Treat 3 months |
| 3 | R-073 | GDPR major breach | 16 | 6 | Exceeds | Critical - Treat Immediate |
| 4 | R-028 | Datacenter outage | 12 | 9 | Exceeds | High - Treat 6 months |
| 5 | R-091 | Talent retention | 12 | 12 | At appetite | Medium - Monitor |
| 6 | R-056 | Market disruption | 12 | 12 | At appetite | Medium - Monitor |
| 7 | R-134 | Project overrun | 9 | 12 | Within | Low - Accept |
| 8 | R-102 | FX volatility | 9 | 9 | At appetite | Medium - Monitor |
| 9 | R-067 | Product recall | 8 | 9 | Within | Low - Accept |
| 10 | R-143 | Office fire | 6 | 9 | Within | Low - Accept |

Treatment Plan Summary:
- 4 risks require immediate treatment (R-042, R-015, R-073, R-028)
- 3 risks acceptable, monitor (R-091, R-056, R-102)
- 3 risks acceptable, routine monitoring (R-134, R-067, R-143)
```

### 6.5 Risk Treatment

**Purpose:** Select and implement options to address risk

**ISO 31000 Treatment Options:**

1. **Avoiding the risk** - Eliminate activity
2. **Taking or increasing risk** - Pursue opportunity
3. **Removing the risk source** - Eliminate root cause
4. **Changing the likelihood** - Implement preventive controls
5. **Changing the consequences** - Implement mitigation controls
6. **Sharing the risk** - Transfer (insurance, outsourcing)
7. **Retaining the risk by informed decision** - Accept

**Treatment Selection Factors:**
- Cost-benefit (is treatment cost-effective?)
- Feasibility (can we implement it?)
- Effectiveness (how much will risk be reduced?)
- Residual risk (will it bring risk within appetite?)
- Secondary risks (does treatment introduce new risks?)

**Treatment Plan (same as ISO 27005 6.4.2):**
- Actions to implement
- Resources required
- Responsibilities
- Timeline
- Expected outcome (target residual risk)

**Risk Treatment Example:** (see ISO 27005 section 6.4 for detailed examples)

### 6.6 Monitoring and Review

**Purpose:** Ensure risk management remains effective

**Monitoring (Continuous):**
- Track Key Risk Indicators (KRIs)
- Monitor treatment plan progress
- Detect new risks or changes to existing risks
- Monitor external environment (threats, regulations)

**Review (Periodic):**
- Regular risk register reviews (monthly, quarterly)
- Annual risk assessment refresh
- Post-incident reviews
- Post-project reviews (lessons learned)

**What to Monitor:**
- Risk levels (are they changing?)
- Control effectiveness (are controls working?)
- Treatment progress (on track?)
- KRIs (early warning indicators)
- Context changes (new regulations, threats?)

**Example KRIs:**
```
Key Risk Indicators (KRI) Dashboard

IT/Cybersecurity Risks:
- Phishing click rate: 5% (Target: < 3%) ⚠️
- Unpatched critical vulnerabilities: 2 (Target: 0) ⚠️
- Failed login attempts: 150/day (Baseline: 100/day) ⚠️
- Backup success rate: 99% (Target: > 98%) ✅
- Mean time to detect incidents: 4 hours (Target: < 6 hours) ✅

Operational Risks:
- Supply chain disruptions: 1 this quarter (Baseline: 0.5/quarter) ⚠️
- Process defect rate: 2% (Target: < 3%) ✅
- Employee turnover: 15% annual (Target: < 20%) ✅

Compliance Risks:
- Overdue audit findings: 3 (Target: 0) ⚠️
- Training completion rate: 85% (Target: 90%) ⚠️
- Policy acknowledgment rate: 95% (Target: 100%) ⚠️

⚠️ = Exceeds threshold, investigate
✅ = Within acceptable range
```

### 6.7 Recording and Reporting

**Purpose:** Document risk management process and communicate results

**What to Record:**
- Risk assessments (methodology, results, assumptions)
- Risk register (all identified risks, scores, treatments)
- Treatment plans (actions, owners, timelines, progress)
- Monitoring results (KRIs, reviews, incidents)
- Decisions (risk acceptance, treatment approval)
- Lessons learned (post-incident, post-project)

**Why Record:**
- Accountability (who decided what and why?)
- Auditability (demonstrate compliance, due diligence)
- Learning (lessons learned for future)
- Communication (inform stakeholders)

**Reporting Frequency and Audience:**
- **Daily/Weekly**: Risk owners (treatment progress, KRIs)
- **Monthly**: Management, Risk Committee (risk register, deep dives)
- **Quarterly**: Executives, Board (top risks, strategic decisions)
- **Annually**: All stakeholders, regulators (comprehensive risk report)
- **Ad-hoc**: Incidents, material changes, escalations

**Example Report Structure:**
```
Quarterly Risk Report to Board

1. Executive Summary (1 page)
   - Overall risk profile (heatmap)
   - Top 5 risks and status
   - Key decisions required

2. Risk Landscape (2 pages)
   - Total risks by level (Critical: 3, High: 15, Medium: 42, Low: 67)
   - Trend (5 risks increased, 8 decreased, 114 stable)
   - New risks this quarter (2 new risks identified)

3. Top 10 Risks (5 pages)
   - Detailed profile per risk (score, appetite, treatment, progress)

4. Risk Treatment Progress (2 pages)
   - 25 treatment plans: 15 on track, 7 delayed, 3 completed

5. Incidents and Lessons Learned (1 page)
   - 2 incidents this quarter (both had identified risks)
   - Lessons learned: [summary]

6. Risk Appetite Compliance (1 page)
   - 18 risks exceeding appetite (down from 25 last quarter)
   - All have approved treatment plans

7. Emerging Risks (1 page)
   - AI/ML security risks (monitoring)
   - New regulation (NIS2 implementation)

8. Decisions Required (1 page)
   - Accept residual risk for R-042 (ransomware) after treatment
   - Approve €500k budget for supply chain diversification (R-015)

Appendices:
- Full risk register (127 risks)
- Detailed KRI dashboard
```

---

## Key Definitions (ISO 31000)

**Risk**: Effect of uncertainty on objectives
- Can be positive (opportunity) or negative (threat)
- Characterized by impact and likelihood

**Risk Management**: Coordinated activities to direct and control an organization with regard to risk

**Risk Appetite**: Amount and type of risk an organization is willing to pursue or retain

**Risk Tolerance**: Acceptable deviation around risk appetite

**Risk Attitude**: Organization's approach to assess and eventually pursue, retain, take, or turn away from risk

**Risk Owner**: Person or entity with accountability and authority to manage a risk

**Stakeholder**: Person or organization that can affect, be affected by, or perceive themselves to be affected by a decision or activity

**Control**: Measure that maintains and/or modifies risk (also called "treatment")

**Residual Risk**: Risk remaining after risk treatment

**Risk Criteria**: Terms of reference against which the significance of a risk is evaluated

---

## ISO 31000 vs. ISO 27005 Comparison

| Aspect | ISO 31000:2018 | ISO 27005:2022 |
|--------|----------------|----------------|
| **Scope** | Generic risk management (all risk types) | Information security risk management |
| **Applicability** | All organizations, all industries | Organizations with ISMS (ISO 27001) |
| **Risk Focus** | Strategic, operational, financial, project, compliance, any risk | Information security risks (confidentiality, integrity, availability) |
| **Structure** | Principles (4) + Framework (5) + Process (6) | Process focus (Clause 6), references ISO 31000 principles |
| **Status** | Guidance (non-certifiable) | Guidance (non-certifiable, supports ISO 27001) |
| **Treatment Options** | 7 options (avoid, take, remove source, change likelihood, change consequences, share, retain) | 4 options (reduce, accept, avoid, transfer) |
| **Integration** | Integrate into any management system | Integrate into ISMS (ISO 27001) |
| **Terminology** | Aligned with ISO Guide 73 | Aligned with ISO 31000 + ISO 27000 |

**When to Use:**
- **ISO 31000**: Enterprise-wide risk management, all risk types
- **ISO 27005**: Information security risk management (required for ISO 27001 compliance)
- **Both Together**: Apply ISO 31000 principles and framework, use ISO 27005 for detailed IT/security risk methodology

---

## ISO 31000 Compliance Checklist

**Clause 4: Principles**
- ☐ Risk management integrated into organizational activities
- ☐ Structured and comprehensive approach
- ☐ Customized to organization's context
- ☐ Stakeholders included in risk management
- ☐ Dynamic (continuous, adapting to changes)
- ☐ Based on best available information
- ☐ Human and cultural factors considered
- ☐ Continual improvement process

**Clause 5: Framework**
- ☐ Leadership commitment demonstrated
- ☐ Risk management integrated into governance, strategy, operations
- ☐ Framework designed (policy, roles, resources, communication)
- ☐ Framework implemented across organization
- ☐ Framework effectiveness evaluated
- ☐ Framework continuously improved

**Clause 6: Process**
- ☐ Communication and consultation throughout process
- ☐ Scope, context, and criteria defined
- ☐ Risk assessment conducted (identify, analyze, evaluate)
- ☐ Risk treatment selected and implemented
- ☐ Risks monitored and reviewed
- ☐ Risk information recorded and reported

---

## Summary

ISO 31000:2018 provides a comprehensive framework for managing risk:

**8 Principles:**
1. Integrated - Embedded in all activities
2. Structured and Comprehensive - Consistent methodology
3. Customized - Tailored to context
4. Inclusive - Stakeholder engagement
5. Dynamic - Continuous, adapting
6. Best Available Information - Data-driven
7. Human and Cultural Factors - Behavior matters
8. Continual Improvement - Learn and evolve

**Framework (Clause 5):**
- Leadership commitment
- Integration into organization
- Design, implement, evaluate, improve

**Process (Clause 6):**
- Communication and consultation (continuous)
- Scope, context, criteria
- Risk assessment (identify, analyze, evaluate)
- Risk treatment
- Monitoring and review
- Recording and reporting

**Key Success Factors:**
1. Top management commitment
2. Integration into strategy and operations (not standalone)
3. Customization to organization's context
4. Stakeholder involvement
5. Data-driven assessments
6. Continuous monitoring and improvement
7. Clear communication and reporting

ISO 31000 provides universal risk management guidance applicable to any organization, industry, or risk type. When combined with ISO 27005 for IT/security risks, ISO 22301 for BC risks, and ISO 27001 for ISMS, organizations achieve comprehensive, integrated risk management. 🎯
