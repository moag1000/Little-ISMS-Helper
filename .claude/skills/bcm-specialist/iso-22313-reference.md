# ISO 22313:2020 - Business Continuity Management Systems
## Guidance on the Use of ISO 22301 - Quick Reference

### Purpose of ISO 22313

ISO 22313 provides **guidance and recommendations** for implementing ISO 22301 requirements. It is not a certifiable standard, but offers best practices and practical advice.

### BIA Guidance (Clause 8.2.2)

**Step 1: Planning the BIA**
- Define scope (which parts of organization to analyze)
- Establish BIA team (cross-functional representation)
- Define methodology (interviews, questionnaires, workshops, data analysis)
- Set timelines and milestones

**Step 2: Information Gathering**

**Interview Questions to Ask Process Owners:**
1. What does this process do? (description)
2. When does it run? (schedule, peaks, dependencies on time)
3. Who performs it? (staff, skills, numbers)
4. What resources does it need? (technology, facilities, supplies)
5. What would happen if it stopped? (impacts by timeframe)
   - After 1 hour?
   - After 4 hours?
   - After 1 day?
   - After 1 week?
6. What is the maximum downtime we can tolerate? (MTPD)
7. What dependencies exist? (upstream, downstream, external)
8. Are there seasonal variations? (peak times, quiet periods)
9. What is the minimum level of service? (degraded mode acceptable?)
10. What data is critical? (maximum acceptable data loss = RPO)

**Step 3: Impact Analysis**

**Impact Categories to Assess:**

**Financial Impacts:**
- Direct revenue loss (sales not made, services not delivered)
- Contractual penalties (SLA breaches, late delivery fees)
- Recovery costs (overtime, expedited shipping, external consultants)
- Regulatory fines (non-compliance, late reporting)
- Asset value loss (spoilage, theft, damage)
- Market opportunity loss (competitors gain advantage)

**Customer/Stakeholder Impacts:**
- Customer satisfaction reduction
- Customer defection (switching to competitors)
- Market share erosion
- Service level degradation
- Complaints and escalations

**Reputation Impacts:**
- Brand damage (media coverage, social media)
- Stakeholder confidence loss (investors, partners)
- Industry standing reduction
- Competitive position weakening

**Legal/Regulatory Impacts:**
- Non-compliance with laws/regulations
- License or certification loss
- Legal action (lawsuits, investigations)
- Reporting obligations (disclosure requirements)

**Operational Impacts:**
- Productivity loss (staff idle, work backlog)
- Safety hazards (health & safety risks)
- Environmental damage (pollution, waste)
- Quality degradation (defects, errors)
- Capability loss (skills attrition, knowledge loss)

**Step 4: Time-Based Impact Analysis**

For each critical activity, assess impacts at these intervals:
- **1 hour**: Immediate impacts
- **4 hours**: Short-term impacts
- **8 hours**: Within business day
- **24 hours**: Next business day
- **3 days**: Extended disruption
- **1 week**: Prolonged disruption
- **2 weeks**: Long-term disruption

**Example: Online Retail System**
| Time | Financial | Customer | Reputation | Regulatory |
|------|-----------|----------|------------|------------|
| 1h | €1,000 lost sales | Minor inconvenience | None | None |
| 4h | €5,000 lost sales | Customer complaints | Social media mentions | None |
| 24h | €120,000 lost sales | Customer defection | News coverage | None |
| 3d | €360,000 lost sales | Major defection | Brand damage | Possible investigation |

**Step 5: Determining RTO, RPO, MTPD**

**RTO (Recovery Time Objective):**
- Time to restore activity to minimum acceptable level
- Set based on impact analysis: When do impacts become severe?
- Consider:
  - Financial impact trajectory
  - Customer tolerance
  - Regulatory requirements
  - Operational needs
- Typical RTOs:
  - **Critical**: 1-4 hours
  - **High**: 4-24 hours
  - **Medium**: 1-3 days
  - **Low**: 1 week or more

**RPO (Recovery Point Objective):**
- Maximum acceptable data loss (time)
- Set based on:
  - Data change frequency
  - Ability to recreate data
  - Regulatory requirements
  - Financial impact of data loss
- Typical RPOs:
  - **Critical transactional data**: 0-15 minutes (near-zero data loss)
  - **Important data**: 1-4 hours
  - **Standard data**: 24 hours
  - **Historical data**: Days or weeks

**MTPD (Maximum Tolerable Period of Disruption):**
- Point of no return (organization viability threatened)
- Typically 2-5x the RTO
- Set by:
  - Strategic impact (market position, brand)
  - Financial reserves (cash flow, credit)
  - Regulatory requirements (license retention)
  - Stakeholder patience (investors, customers)

**Relationship Between RTO, RPO, MTPD:**
```
0 -------- RTO -------- MTPD --------- [Organizational Failure]
|            |            |
Incident   Target      Point of
Occurs     Recovery    No Return

RPO <------|  (Data loss window)
```

**Step 6: Dependency Mapping**

**Internal Dependencies:**
- Which processes depend on this process?
- Which processes does this process depend on?
- What are single points of failure?

**External Dependencies:**
- Suppliers (goods, services, utilities)
- Partners (joint operations, shared services)
- Customers (order volume, payment terms)
- Infrastructure (power, water, telecom, internet, cloud providers)
- Government services (emergency services, regulatory agencies)

**Resource Dependencies:**
- Personnel (skills, numbers, key individuals)
- Facilities (workspace, utilities, access)
- Technology (systems, networks, devices)
- Information (data, records, documentation)
- Supplies (materials, inventory, consumables)

**Dependency Analysis Questions:**
1. What would happen if this dependency failed?
2. How long can we operate without it?
3. Are there alternatives or workarounds?
4. What is the dependency's BC capability?
5. Can we influence the dependency's resilience?

### BC Strategy Guidance (Clause 8.3)

**Strategy Options Matrix:**

| Strategy | Cost | Recovery Time | Resilience | Use Case |
|----------|------|---------------|------------|----------|
| **Do Nothing** | None | N/A | Low | Non-critical activities |
| **Manual Workaround** | Low | Slow (days) | Medium | Infrequent activities, temporary solution |
| **Reciprocal Agreement** | Low | Medium (hours-days) | Medium | With similar organizations, mutual aid |
| **Cold Site** | Low-Medium | Slow (3-7 days) | Medium | Empty facility, bring your own equipment |
| **Warm Site** | Medium | Medium (1-3 days) | High | Partially equipped, some IT systems |
| **Hot Site** | High | Fast (hours) | Very High | Fully equipped, ready to activate |
| **Real-Time Replication** | Very High | Immediate (minutes) | Very High | Mission-critical, zero downtime tolerance |
| **Work From Home** | Low | Fast (hours) | High | Knowledge work, no physical presence needed |
| **Mobile/Portable Facilities** | Medium | Medium (1-2 days) | High | Temporary workspace deployment |

**Strategy Selection Criteria:**

**For RTO ≤ 4 hours:** Hot site, real-time replication, work from home
**For RTO 4-24 hours:** Warm site, work from home, mobile facilities
**For RTO 1-3 days:** Cold site, reciprocal agreements, manual workarounds
**For RTO > 3 days:** Do nothing (low priority), manual workarounds

**Technology Recovery Strategies:**

**Option 1: Backup and Restore**
- Regular backups (daily, hourly, continuous)
- Offsite storage (geographic separation)
- Restore procedures tested
- RTO: Days to hours (depending on backup frequency and restore speed)
- RPO: Hours to minutes (depending on backup frequency)
- Cost: Low to Medium

**Option 2: Warm Standby**
- Duplicate environment with periodic sync
- Data replicated daily or hourly
- Systems ready but not live
- RTO: Hours
- RPO: Hours
- Cost: Medium

**Option 3: Hot Standby**
- Duplicate environment with real-time sync
- Systems live and ready for failover
- Automatic or manual failover
- RTO: Minutes to hours
- RPO: Minutes
- Cost: High

**Option 4: Active-Active (High Availability)**
- Multiple live systems in load balance
- Geographic distribution
- Automatic failover
- RTO: Seconds to minutes
- RPO: Near-zero
- Cost: Very High

**Facility Recovery Strategies:**

**Work From Home:**
- **Advantages**: Low cost, fast activation, flexible
- **Requirements**: Home office setup, VPN access, collaboration tools
- **Limitations**: Not suitable for physical work, security concerns
- **Best for**: Knowledge workers, office staff

**Reciprocal Agreement:**
- **Advantages**: Low cost, mutual benefit
- **Requirements**: Similar organization, documented agreement, regular testing
- **Limitations**: May not be available when needed (both affected), limited capacity
- **Best for**: Small organizations, specialized industries

**Third-Party Site:**
- **Advantages**: Professional setup, scalable, tested
- **Requirements**: Contract, regular testing, staff training
- **Limitations**: Shared with others, geographic constraints, cost
- **Best for**: Medium to large organizations, predictable needs

**Mobile Facilities:**
- **Advantages**: Flexible location, quick deployment
- **Requirements**: Pre-positioning or rapid deployment, utilities hookup
- **Limitations**: Limited capacity, weather-dependent
- **Best for**: Short-term needs, physical operations

### BC Plan Development Guidance (Clause 8.4.4)

**Plan Structure Best Practices:**

**1. Executive Summary**
- Purpose and scope
- Key contacts (one-page contact list)
- Quick reference (plan activation flowchart)

**2. Activation Section**
- **Clear Trigger Criteria**: Specific, measurable
  - Good: "Database unavailable for > 30 minutes"
  - Bad: "Major incident"
- **Activation Authority**: Who decides? (Name, role, alternate)
- **Activation Procedure**:
  1. Assess incident severity
  2. Consult with [specific roles]
  3. Decide to activate plan
  4. Notify response team
  5. Log activation time and reason

**3. Response Team**
- **Incident Commander**: Overall authority, strategic decisions
- **Recovery Coordinator**: Tactical execution, resource mobilization
- **Technical Lead**: IT/technology recovery
- **Communications Lead**: Internal & external communications
- **Business Unit Representatives**: Subject matter experts
- Each role needs:
  - Primary person (name, contact, availability)
  - Backup person (name, contact, availability)
  - Responsibilities (detailed task list)
  - Authority level (what can they decide?)

**4. Response Actions (First Hour)**
Step-by-step actions in sequence:
1. Activate emergency operations center (or virtual equivalent)
2. Conduct initial damage assessment
3. Ensure personnel safety
4. Secure physical and information assets
5. Notify response team
6. Establish communication protocols
7. Begin initial recovery actions
8. Notify senior management
9. Log all actions and decisions

**5. Recovery Procedures**
**Critical**: Step-by-step instructions for each system/process
- **What**: What needs to be recovered
- **How**: Specific recovery steps (1, 2, 3...)
- **Who**: Who performs each step (role or name)
- **When**: Sequence and timing
- **Resources**: What is needed (access, tools, information)
- **Success Criteria**: How do you know it worked?

**Example: Database Recovery Procedure**
```
1. Access backup storage (AWS S3 bucket: backup-prod-db)
   - Performed by: DBA or Technical Lead
   - Required: AWS credentials (stored in password manager)
   - Duration: 5 minutes

2. Identify latest valid backup
   - Check backup timestamp < RPO (4 hours)
   - Verify backup integrity (checksum)
   - Duration: 10 minutes

3. Provision recovery environment
   - If primary available: Use primary
   - If primary unavailable: Use hot standby (AWS RDS instance: prod-db-standby)
   - Duration: 5 minutes (if standby) or 30 minutes (if new instance)

4. Restore database from backup
   - Command: aws rds restore-db-instance-from-s3 ...
   - Duration: 60 minutes (estimated)

5. Verify data integrity
   - Run validation queries
   - Compare record counts to expected
   - Duration: 15 minutes

6. Redirect application traffic
   - Update DNS or load balancer
   - Duration: 5 minutes

7. Monitor and validate
   - Check application logs
   - Confirm user access
   - Duration: 15 minutes

Total Estimated RTO: 2 hours
Actual RTO: [To be recorded during recovery]
```

**6. Communication**

**Internal Communication:**
- **Staff Notification**:
  - How: Email, SMS, phone tree, collaboration platform (Teams, Slack)
  - When: Within 15 minutes of activation
  - Message template: "BC Plan Activated - [Scenario] - [Expected Duration] - [Actions Required]"
- **Status Updates**:
  - Frequency: Every 2 hours minimum
  - Format: Status report template
  - Channels: Email, intranet, team meetings

**External Communication:**
- **Customer Notification**:
  - When: Within 30 minutes if customer-facing services affected
  - How: Website banner, email, social media, phone (for VIPs)
  - Message template: Acknowledge issue, explain impact, provide ETA, offer alternatives
- **Supplier Notification**:
  - When: If supplier action needed
  - Message: Explain situation, request support or alternative arrangements
- **Regulator Notification**:
  - When: As required by regulation (e.g., NIS2: 24 hours early warning, 72 hours full report)
  - Format: Official report format
- **Media Relations**:
  - Designated spokesperson only
  - Approved messages only
  - Coordinate with Communications Lead

**Communication Templates:**
Prepare pre-approved templates for common scenarios:
- "Service Unavailable" (website banner)
- "We're Working on It" (customer email)
- "Extended Outage" (customer email with alternatives)
- "Service Restored" (all clear message)
- "Incident Report" (regulator report)

**7. Resource Mobilization**

**Personnel:**
- Who needs to be called in?
- Where should they report?
- What are their assignments?
- How are they relieved after extended periods?

**Facilities:**
- Alternative work locations (addresses, access procedures)
- Capacity and amenities
- IT connectivity and equipment
- How to get there (maps, parking, public transport)

**Technology:**
- Backup systems and how to access
- Failover procedures
- Equipment (laptops, phones, tablets) and where to get them
- Vendor contacts and support procedures

**Supplies:**
- Critical inventory levels
- Emergency procurement procedures
- Supplier contacts (primary and backup)

**Financial:**
- Budget authorization (who can approve emergency spending?)
- P-card or credit limits
- Expense tracking and reporting

**8. Alternative Arrangements**

**Alternative Work Area:**
- **Location**: Full address, directions, parking
- **Capacity**: How many people can work there?
- **Amenities**: Power, HVAC, restrooms, kitchen, security
- **IT Connectivity**: Internet, phones, VPN access
- **Equipment**: Desks, chairs, computers, printers
- **Access**: How to get keys/badges, security procedures
- **Activation Time**: How long to set up?
- **Cost**: Rental fees, setup costs, ongoing costs
- **Contract**: Contact person, terms, renewal

**Technology Failover:**
- **Hot Standby**: System name, location, activation procedure
- **Manual Workarounds**: Paper forms, manual processes, degraded service mode
- **Alternative Providers**: Cloud services, SaaS alternatives

**Supplier Alternatives:**
- **Primary Supplier**: [Company A]
- **Backup Supplier**: [Company B] - Pre-qualified, tested, contact: [name, phone]
- **Emergency Supplier**: [Company C] - Higher cost, can deliver within 24h

### Exercise and Testing Guidance (Clause 8.5)

**Annual Exercise Program:**

**Q1: Tabletop Exercise**
- Objective: Test communication and decision-making
- Scenario: [Realistic disruption scenario]
- Duration: 2-3 hours
- Participants: Response team, key stakeholders
- Cost: Low (internal facilitation)

**Q2: Component Test**
- Objective: Test specific system recovery (e.g., database restore)
- Scope: One critical system
- Duration: 4-8 hours
- Participants: Technical team
- Cost: Low to Medium (may need vendor support)

**Q3: Tabletop Exercise**
- Objective: Test different scenario
- Scenario: [Different from Q1]
- Duration: 2-3 hours
- Participants: Response team, different stakeholders
- Cost: Low

**Q4: Full Test (or Simulation)**
- Objective: Test complete plan activation
- Scope: All critical systems and processes
- Duration: Full day or weekend
- Participants: All response team members
- Cost: Medium to High (may need to activate alternative site)

**Exercise Scenario Development:**

**Good Scenario Characteristics:**
- **Realistic**: Based on actual risk assessment
- **Challenging**: Tests key aspects of plan
- **Specific**: Clear start conditions and events
- **Measurable**: Clear success criteria
- **Relevant**: Addresses current concerns
- **Controlled**: Can be stopped if needed

**Example Scenario: Ransomware Attack**
```
**Scenario**: Ransomware Attack on Core Systems

**Initial Situation**:
- Date: [Exercise Date]
- Time: 09:00 Monday morning
- Event: IT team discovers ransomware infection
- Affected Systems:
  - File servers (encrypted)
  - Email system (partially affected)
  - Customer database (offline for safety)
- Not Affected:
  - Phone system
  - Web servers (isolated in DMZ)
  - Backup systems (offline, protected)

**Injects** (events introduced during exercise):
- T+30min: Ransom note found demanding Bitcoin payment within 48 hours
- T+1h: Media contacts company for comment (leak?)
- T+2h: Key customer calls asking about order status
- T+4h: Backup restoration taking longer than expected (compatibility issue)
- T+6h: Management asks for recovery ETA

**Success Criteria**:
- [ ] BC plan activated within 30 minutes
- [ ] Response team assembled within 1 hour
- [ ] Customer notification sent within 2 hours
- [ ] Backup restoration procedure initiated within 2 hours
- [ ] Internal communication updates every 2 hours
- [ ] Management briefed within 4 hours
- [ ] Media response coordinated (no unauthorized statements)
- [ ] Recovery completed within RTO (8 hours)

**Observer Notes**:
- Record all decisions and timings
- Note communication effectiveness
- Identify gaps in procedures
- Document questions that arise
- Capture lessons learned
```

**Post-Exercise Reporting Template:**

```
**BC Exercise Report**
Exercise ID: [EX-2024-01]
Date: [Date]
Exercise Type: [Tabletop/Simulation/Full]
Plan Tested: [BC Plan Name]

**1. Executive Summary**
- Scenario: [Brief description]
- Participants: [Count and roles]
- Duration: [X hours]
- Overall Assessment: [Success/Partial Success/Needs Improvement]

**2. Objectives Met**
- [Objective 1]: ✅ Met / ⚠️ Partially Met / ❌ Not Met
- [Objective 2]: ✅ Met / ⚠️ Partially Met / ❌ Not Met
- [Objective 3]: ✅ Met / ⚠️ Partially Met / ❌ Not Met

**3. What Went Well (WWW)**
- Activation decision made quickly and correctly
- Communication protocols followed effectively
- Technical recovery procedures worked as documented
- [Additional positive observations]

**4. Areas for Improvement (AFI)**
- Contact information outdated (2 people unreachable)
- Backup restoration took 2x longer than expected
- Customer notification template missing key information
- [Additional improvement needs]

**5. Findings**
1. **Finding**: Contact list has outdated phone numbers
   - **Impact**: Medium (delayed team assembly)
   - **Recommendation**: Implement quarterly contact verification

2. **Finding**: Backup restoration procedure assumes compatibility
   - **Impact**: High (RTO exceeded)
   - **Recommendation**: Test restore on current production version quarterly

3. **Finding**: No guidance on media inquiries
   - **Impact**: Low (handled but improvised)
   - **Recommendation**: Add media response template to communication plan

**6. Action Items**
| # | Action | Owner | Due Date | Priority | Status |
|---|--------|-------|----------|----------|--------|
| 1 | Update contact list | IT Manager | [Date] | High | Open |
| 2 | Test backup restore compatibility | DBA | [Date] | High | Open |
| 3 | Create media response template | Comms | [Date] | Medium | Open |
| 4 | Add alternative supplier contact to plan | Procurement | [Date] | Medium | Open |

**7. Plan Updates Required**
- Update Section 3 (Contact List) - Page 12
- Update Section 7 (Backup Restore Procedure) - Page 34
- Add Appendix D (Media Response Templates)

**8. Lessons Learned**
- Regular contact verification is essential
- Backup procedures must be tested with current production versions
- Pre-approved communication templates speed response
- [Additional lessons]

**9. Next Steps**
- Complete action items by [date]
- Update BC plan by [date]
- Schedule next exercise (Q3): [exercise type]

**Report Prepared By**: [Name, Role]
**Report Date**: [Date]
**Distribution**: Response Team, Management, BC Steering Committee
```

### Integration with ISO 27001

**ISO 27001 ↔ ISO 22301 Mapping:**

| ISO 27001 Control | ISO 22301 Clause | Integration Point |
|-------------------|------------------|-------------------|
| A.5.29 Information Security during Disruption | 8.4.4 BC Plans | Security procedures in BC plans |
| A.5.30 ICT Readiness for Business Continuity | 8.3 BC Strategy | IT recovery strategy |
| A.8.13 Information Backup | 8.4.4 BC Plans | Backup procedures in BC plans |
| A.8.14 Redundancy | 8.3 BC Strategy | Redundancy as BC strategy |
| Clause 6 Risk Assessment | 8.2.3 Risk Assessment | BCM risks in risk register |
| A.6.8 Capacity Management | 8.2.2 BIA | Capacity as resource requirement |
| A.5.24 Security Incident Management | 8.4.2 Incident Response | Incident response integration |

**Practical Integration Tips:**

1. **Use Same Risk Assessment Process**: One risk assessment feeding both ISMS and BCMS
2. **Align RTO with Security Requirements**: If process handles sensitive data, tighter RTO needed
3. **Include Security in BC Procedures**: Authentication, authorization, encryption during recovery
4. **Test Security During BC Exercises**: Verify security controls work in degraded mode
5. **Document in Both Systems**: BC plan activation logged in both BCM and security incident logs

### Best Practices Summary

**1. Keep It Simple**
- Use clear, plain language
- Avoid jargon and acronyms
- Step-by-step instructions
- Visual aids (flowcharts, diagrams)

**2. Make It Accessible**
- Online and offline copies
- Paper copies in secure locations
- Mobile-friendly formats
- Easy to find (known locations)

**3. Keep It Current**
- Regular reviews (minimum annually)
- Update after exercises
- Update after incidents
- Update after organizational changes

**4. Test Realistically**
- Use realistic scenarios
- Include unexpected events (injects)
- Test at inconvenient times
- Don't skip steps

**5. Learn and Improve**
- Capture lessons learned
- Implement improvements
- Close action items
- Measure effectiveness

**6. Integrate with Other Systems**
- Link with ISMS (ISO 27001)
- Link with QMS (ISO 9001)
- Link with EMS (ISO 14001)
- Link with OHSAS (ISO 45001)

**7. Communicate Effectively**
- Regular awareness training
- Clear roles and responsibilities
- Pre-approved message templates
- Multiple communication channels

**8. Manage Dependencies**
- Map all critical dependencies
- Assess supplier BC capability
- Develop alternative arrangements
- Test with suppliers

**9. Document Everything**
- Decisions and rationale
- Exercise results
- Incident lessons
- Plan updates

**10. Engage Management**
- Regular reporting
- Exercise participation
- Resource allocation
- Strategic alignment
